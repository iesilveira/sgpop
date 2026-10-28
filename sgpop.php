<?php
/**
 * Plugin Name: SPSES - Gestão de Procedimentos Operacionais
 * Description: Gerencie POPs na intranet com editor do WordPress, temas, dados operacionais e histórico de revisões.
 * Version: 1.0
 * Author: Ismael Elói da Silveira Silva
 * Text Domain: sgpop
 * Requires at least: 6.2
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SGPOP_VERSION', '1.0' );

final class SGPOP_Plugin {
	const POST_TYPE = 'sgpop_pop';
	const TAX_THEME = 'sgpop_tema';
	const TAX_AREA  = 'sgpop_area';
	const NONCE     = 'sgpop_save_meta';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_content_types' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );
		add_action( 'post_updated', array( __CLASS__, 'track_published_revision' ), 10, 3 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	public static function register_content_types() {
		$labels = array(
			'name'                  => 'Procedimentos Operacionais',
			'singular_name'         => 'POP',
			'menu_name'             => 'Gestão de POPs',
			'add_new'               => 'Adicionar POP',
			'add_new_item'          => 'Adicionar novo POP',
			'edit_item'             => 'Editar POP',
			'new_item'              => 'Novo POP',
			'view_item'             => 'Visualizar POP',
			'search_items'          => 'Buscar POPs',
			'not_found'             => 'Nenhum POP encontrado',
			'not_found_in_trash'    => 'Nenhum POP na lixeira',
			'all_items'             => 'Todos os POPs',
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'                => $labels,
				'public'                => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_rest'          => true,
				'exclude_from_search'   => true,
				'publicly_queryable'    => false,
				'has_archive'           => false,
				'rewrite'               => false,
				'menu_icon'             => 'dashicons-media-document',
				'menu_position'         => 25,
				'supports'              => array( 'title', 'editor', 'excerpt', 'author', 'revisions' ),
				'capability_type'       => 'post',
				'map_meta_cap'          => true,
			)
		);

		$taxonomies = array(
			self::TAX_THEME => array(
				'name'          => 'Temas',
				'singular_name' => 'Tema',
				'menu_name'     => 'Temas',
			),
			self::TAX_AREA => array(
				'name'          => 'Áreas',
				'singular_name' => 'Área',
				'menu_name'     => 'Áreas',
			),
		);

		foreach ( $taxonomies as $taxonomy => $taxonomy_labels ) {
			register_taxonomy(
				$taxonomy,
				array( self::POST_TYPE ),
				array(
					'labels'            => $taxonomy_labels,
					'hierarchical'      => true,
					'public'            => false,
					'show_ui'           => true,
					'show_admin_column' => true,
					'show_in_rest'      => true,
					'rewrite'           => false,
				)
			);
		}
	}

	public static function add_meta_boxes() {
		add_meta_box(
			'sgpop_document_details',
			'Identificação e controle do POP',
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
		add_meta_box(
			'sgpop_revision_history',
			'Versões oficiais registradas',
			array( __CLASS__, 'render_history_box' ),
			self::POST_TYPE,
			'normal',
			'default'
		);
	}

	private static function get_meta( $post_id, $key ) {
		return get_post_meta( $post_id, '_sgpop_' . $key, true );
	}

	public static function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, self::NONCE );
		$fields = array(
			'codigo'       => array( 'Código do POP', 'text' ),
			'responsavel'  => array( 'Responsável', 'text' ),
			'vigencia'     => array( 'Data de vigência', 'date' ),
			'revisao'      => array( 'Próxima revisão', 'date' ),
			'alteracao'    => array( 'Resumo da alteração', 'text' ),
		);

		echo '<p><label for="sgpop_versao"><strong>Versão oficial</strong></label><br>';
		printf(
			'<input class="widefat" id="sgpop_versao" type="text" value="%s" readonly></p>',
			esc_attr( self::get_meta( $post->ID, 'versao' ) ? self::get_meta( $post->ID, 'versao' ) : 'Ainda não publicado' )
		);
		echo '<p class="description">A versão oficial começa em 1.0 na primeira publicação e aumenta quando título, resumo ou conteúdo de um POP publicado é alterado. O histórico detalhado de edição também fica disponível em Revisões do WordPress.</p>';

		foreach ( $fields as $key => $field ) {
			printf(
				'<p><label for="sgpop_%1$s"><strong>%2$s</strong></label><br><input class="widefat" id="sgpop_%1$s" name="sgpop_%1$s" type="%3$s" value="%4$s"></p>',
				esc_attr( $key ),
				esc_html( $field[0] ),
				esc_attr( $field[1] ),
				esc_attr( self::get_meta( $post->ID, $key ) )
			);
		}
	}

	public static function render_history_box( $post ) {
		$history = get_post_meta( $post->ID, '_sgpop_history', true );
		if ( ! is_array( $history ) || empty( $history ) ) {
			echo '<p>O histórico de versões oficiais será criado quando o POP for publicado e atualizado.</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Versão</th><th>Data</th><th>Responsável pela edição</th><th>Resumo</th></tr></thead><tbody>';
		foreach ( array_reverse( $history ) as $entry ) {
			$user = isset( $entry['user_id'] ) ? get_userdata( absint( $entry['user_id'] ) ) : false;
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( isset( $entry['version'] ) ? $entry['version'] : '' ),
				esc_html( isset( $entry['date'] ) ? $entry['date'] : '' ),
				esc_html( $user ? $user->display_name : 'Usuário removido' ),
				esc_html( isset( $entry['summary'] ) && $entry['summary'] ? $entry['summary'] : 'Atualização do documento' )
			);
		}
		echo '</tbody></table>';
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array( 'codigo', 'responsavel', 'alteracao' );
		foreach ( $text_fields as $key ) {
			$value = isset( $_POST[ 'sgpop_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'sgpop_' . $key ] ) ) : '';
			update_post_meta( $post_id, '_sgpop_' . $key, $value );
		}
		foreach ( array( 'vigencia', 'revisao' ) as $key ) {
			$value = isset( $_POST[ 'sgpop_' . $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ 'sgpop_' . $key ] ) ) : '';
			if ( '' === $value || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
				update_post_meta( $post_id, '_sgpop_' . $key, $value );
			}
		}
	}

	public static function track_published_revision( $post_id, $post_after, $post_before ) {
		if ( self::POST_TYPE !== $post_after->post_type || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( 'publish' !== $post_after->post_status ) {
			return;
		}

		$version = get_post_meta( $post_id, '_sgpop_versao', true );
		$history = get_post_meta( $post_id, '_sgpop_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$changed = ( $post_after->post_title !== $post_before->post_title )
			|| ( $post_after->post_content !== $post_before->post_content )
			|| ( $post_after->post_excerpt !== $post_before->post_excerpt );

		if ( '' === $version ) {
			$version = '1.0';
			update_post_meta( $post_id, '_sgpop_versao', $version );
			$history[] = array(
				'version' => $version,
				'date'    => current_time( 'mysql' ),
				'user_id' => get_current_user_id(),
				'summary' => 'Primeira publicação',
			);
			update_post_meta( $post_id, '_sgpop_history', $history );
			delete_post_meta( $post_id, '_sgpop_alteracao' );
			return;
		}

		if ( ! $changed ) {
			return;
		}

		$parts = explode( '.', $version );
		$major = isset( $parts[0] ) ? absint( $parts[0] ) : 1;
		$minor = isset( $parts[1] ) ? absint( $parts[1] ) : 0;
		$version = $major . '.' . ( $minor + 1 );
		update_post_meta( $post_id, '_sgpop_versao', $version );
		$summary = get_post_meta( $post_id, '_sgpop_alteracao', true );
		$history[] = array(
			'version' => $version,
			'date'    => current_time( 'mysql' ),
			'user_id' => get_current_user_id(),
			'summary' => $summary,
		);
		update_post_meta( $post_id, '_sgpop_history', $history );
		delete_post_meta( $post_id, '_sgpop_alteracao' );
	}

	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['sgpop_codigo'] = 'Código';
				$new['sgpop_versao'] = 'Versão';
				$new['sgpop_revisao'] = 'Próxima revisão';
			}
		}
		return $new;
	}

	public static function render_admin_column( $column, $post_id ) {
		$map = array(
			'sgpop_codigo'  => 'codigo',
			'sgpop_versao'  => 'versao',
			'sgpop_revisao' => 'revisao',
		);
		if ( isset( $map[ $column ] ) ) {
			echo esc_html( self::get_meta( $post_id, $map[ $column ] ) );
		}
	}

	public static function sortable_columns( $columns ) {
		$columns['sgpop_codigo'] = 'sgpop_codigo';
		$columns['sgpop_versao'] = 'sgpop_versao';
		return $columns;
	}
}

SGPOP_Plugin::init();
