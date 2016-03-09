<?php
namespace WP_Stream;

class Export {
	/**
	 * Hold Plugin class
	 *
	 * @var Plugin
	 */
	public $plugin;

	/**
	 * Hold Admin class
	 *
	 * @var Admin
	 */
	public $admin;

	/**
	 * Hold registered exporters
	 *
	 * @var array
	 */
	public $exporters = array();

	/**
	 * Class constructor
	 *
	 * @param Plugin $plugin The plugin object.
	 * @return void
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
		$this->admin = $plugin->admin;

		if ( 'wp_stream' === wp_stream_filter_input( INPUT_GET, 'page' ) ) {
			add_action( 'admin_init', array( $this, 'render_download' ) );
			add_action( 'wp_stream_after_list_table', array( $this, 'download_links' ) );
			$this->register_exporters();
		}
	}

	/**
	 * Outputs download file to user based on selected exporter
	 *
	 * @return void
	 */
	public function render_download() {
		$this->get_exporters();
		$output_type = wp_stream_filter_input( INPUT_GET, 'output' );
		if ( ! array_key_exists( $output_type, $this->exporters ) ) {
			return;
		}

		$this->admin->register_list_table();
		$list_table = $this->admin->list_table;
		$list_table->prepare_items();
		add_filter( 'stream_records_per_page', array( $this, 'disable_paginate' ) );
		add_filter( 'wp_stream_list_table_columns', array( $this, 'expand_columns' ), 10, 1 );

		$records = $list_table->get_records();
		$columns = $list_table->get_columns();
		$output = array();
		foreach ( $records as $item ) {
			$output[] = $this->build_record( $item, $columns );
		}

		$exporter = $this->exporters[ $output_type ];
		$exporter->output_file( $output, $columns );
		return;
	}

	/*
	 * @return void
	 */
	function download_links() {
		$exporters = $this->plugin->admin->export->get_exporters();
		if ( empty( $exporters ) ) {
			return;
		}

		echo '<div class="stream-export-tablenav">' . esc_html( __( 'Export as: ', 'stream' ) );

		foreach ( array_keys( $exporters ) as $key => $export_type ) {
			$args = array_merge( array( 'output' => $export_type ), $_GET );
			$download = add_query_arg( $args, 'admin.php' );

			echo sprintf(
				'<a href="%s">%s</a> ',
				esc_html( $download ),
				esc_html( $this->plugin->admin->export->exporters[ $export_type ]->name )
			);
		}
		echo '</div>';
	}

	/**
	 * Extracts data from Records
	 *
	 * @param array $item Post to extract data from.
	 * @param array $columns Columns being extracted.
	 * @return array Numerically-indexed array with extracted data.
	 */
	function build_record( $item, $columns ) {
		$record = new Record( $item );

		$row_out = array();
		foreach ( array_keys( $columns ) as $column_name ) {
			switch ( $column_name ) {
				case 'date' :
					$created   = date( 'Y-m-d H:i:s', strtotime( $record->created ) );
					$row_out[ $column_name ] = get_date_from_gmt( $created, 'Y/m/d h:i:s A' );
					break;

				case 'summary' :
					$row_out[ $column_name ] = $record->summary;
					break;

				case 'user_id' :
					$user      = new Author( (int) $record->user_id, (array) maybe_unserialize( $record->user_meta ) );
					$row_out[ $column_name ] = $user->get_display_name();
					break;

				case 'connector':
					$row_out[ $column_name ] = $record->connector;
					break;

				case 'context':
					$row_out[ $column_name ] = $record->context;
					break;

				case 'action':
					$row_out[ $column_name ] = $record->{$column_name};
					break;

				case 'blog_id':
					$row_out[ $column_name ] = $record->blog_id;
					break;

				case 'ip' :
					$row_out[ $column_name ] = $record->{$column_name};
					break;
			}
		}

		return $row_out;
	}

	/**
	 * Increase pagination limit for CSV Output
	 *
	 * @param int $records_per_page Old limit for records_per_page.
	 */
	public function disable_paginate( $records_per_page ) {
		return 10000;
	}

	/**
	 * Expand columns for CSV Output
	 *
	 * @param array $columns Columns currently registered to the list table being exported.
	 * @return array New columns for exporting.
	 */
	public function expand_columns( $columns ) {
		$new_columns = array(
			'date'      => $columns['date'],
			'summary'   => $columns['summary'],
			'user_id'   => $columns['user_id'],
			'connector' => __( 'Connector', 'stream' ),
			'context'   => $columns['context'],
			'action'    => $columns['action'],
			'ip'        => $columns['ip'],
		);

		if ( is_multisite() && is_plugin_active_for_network( $this->plugin->locations['plugin'] ) ) {
			$new_columns['blog_id'] = __( 'Blog ID', 'stream' );
		}

		return $new_columns;
	}

	/**
	 * Registers all available exporters
	 *
	 * @return null
	 */
	public function register_exporters() {
		$exporters = array(
			'csv',
			'json',
		);

		$classes = array();
		foreach ( $exporters as $exporter ) {
			include_once $this->plugin->locations['dir'] . '/exporters/class-exporter-' . $exporter .'.php';
			$class_name = sprintf( '\WP_Stream\Exporter_%s', str_replace( '-', '_', $exporter ) );
			if ( ! class_exists( $class_name ) ) {
				continue;
			}
			$class = new $class_name();
			if ( ! method_exists( $class, 'is_dependency_satisfied' ) ) {
				continue;
			}
			if ( $class->is_dependency_satisfied() ) {
				$classes[] = $class;
			}
		}

		/**
		 * Allows for adding additional exporters via classes that extend Exporter.
		 *
		 * @param array $classes An array of Exporter objects.
		 */
		$this->exporters = apply_filters( 'wp_stream_exporters', $classes );

		// Ensure that all exporters extend Exporter
		foreach ( $this->exporters as $key => $exporter ) {
			if ( ! is_a( $exporter, 'WP_Stream\Exporter' ) ) {
				trigger_error(
					sprintf(
						esc_html__( 'Registered exporter %s does not extend WP_Stream\Exporter.', 'stream' ),
						esc_html( get_class( $exporter ) )
					)
				);
				unset( $this->exporters[ $key ] );
			}
		}
	}

	/**
	 * Returns an array with all available exporters
	 *
	 * @return array
	 */
	public function get_exporters() {
		return $this->exporters;
	}
}
