<?php
add_action(
	'init',
	function () {
		register_post_status(
			'wc-shipped',
			array(
				'label'                     => 'Despachado',
				'public'                    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( 'Despachado (%s)', 'Despachados (%s)' ),
			)
		);
	}
);
add_filter(
	'wc_order_statuses',
	function ( $statuses ) {
		$statuses['wc-shipped'] = 'Despachado';
		return $statuses;
	}
);
