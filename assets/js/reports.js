( function () {
	'use strict';

	const i18n = window.kantanbondReportI18n || {};

	const yen = ( value ) => '¥' + Number( value ).toLocaleString();

	function parsePayload( root ) {
		const node = root.querySelector( '.kantanbond-report-payload' );
		if ( ! node || ! node.textContent ) {
			return null;
		}

		try {
			return JSON.parse( node.textContent );
		} catch ( error ) {
			return null;
		}
	}

	function getCanvas( root, key ) {
		return root.querySelector( 'canvas[data-chart-key="' + key + '"]' );
	}

	function initSalesCharts( root, data ) {
		const monthlyCanvas = getCanvas( root, 'monthly_sales' );
		if ( data.monthly_sales && monthlyCanvas ) {
			new window.Chart( monthlyCanvas, {
				type: 'line',
				data: {
					labels: data.monthly_sales.labels,
					datasets: [ {
						label: i18n.sales_amount || '売上金額',
						data: data.monthly_sales.data,
						borderColor: 'rgb(79, 70, 229)',
						backgroundColor: 'rgba(79, 70, 229, 0.12)',
						fill: true,
						tension: 0.35,
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						y: {
							beginAtZero: true,
							ticks: { callback: ( v ) => yen( v ) },
						},
					},
				},
			} );
		}

		const profitCanvas = getCanvas( root, 'profit_trend' );
		if ( data.profit_trend && profitCanvas ) {
			new window.Chart( profitCanvas, {
				type: 'bar',
				data: {
					labels: data.profit_trend.labels,
					datasets: [
						{
							label: i18n.cost || 'コスト',
							data: data.profit_trend.cost,
							backgroundColor: 'rgba(245, 158, 11, 0.75)',
						},
						{
							label: i18n.profit || '利益',
							data: data.profit_trend.profit,
							backgroundColor: 'rgba(16, 185, 129, 0.75)',
						},
					],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					scales: {
						x: { stacked: true },
						y: {
							stacked: true,
							beginAtZero: true,
							ticks: { callback: ( v ) => yen( v ) },
						},
					},
				},
			} );
		}
	}

	function initClientCharts( root, data ) {
		const salesCanvas = getCanvas( root, 'client_sales' );
		if ( data.client_sales && salesCanvas ) {
			new window.Chart( salesCanvas, {
				type: 'bar',
				data: {
					labels: data.client_sales.labels,
					datasets: [ {
						label: i18n.sales || '売上',
						data: data.client_sales.data,
						backgroundColor: 'rgba(79, 70, 229, 0.7)',
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					indexAxis: 'y',
					plugins: { legend: { display: false } },
					scales: {
						x: {
							beginAtZero: true,
							ticks: { callback: ( v ) => yen( v ) },
						},
					},
				},
			} );
		}

		const ordersCanvas = getCanvas( root, 'client_orders' );
		if ( data.client_orders && ordersCanvas ) {
			new window.Chart( ordersCanvas, {
				type: 'doughnut',
				data: {
					labels: data.client_orders.labels,
					datasets: [ {
						data: data.client_orders.data,
						borderWidth: 0,
						backgroundColor: [
							'rgba(79, 70, 229, 0.75)',
							'rgba(99, 102, 241, 0.75)',
							'rgba(129, 140, 248, 0.75)',
							'rgba(165, 180, 252, 0.75)',
							'rgba(199, 210, 254, 0.75)',
						],
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { position: 'bottom' } },
				},
			} );
		}
	}

	function initServiceCharts( root, data ) {
		const salesCanvas = getCanvas( root, 'service_sales' );
		if ( data.service_sales && salesCanvas ) {
			new window.Chart( salesCanvas, {
				type: 'bar',
				data: {
					labels: data.service_sales.labels,
					datasets: [ {
						data: data.service_sales.data,
						backgroundColor: 'rgba(22, 163, 74, 0.75)',
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					indexAxis: 'y',
					plugins: { legend: { display: false } },
					scales: {
						x: {
							beginAtZero: true,
							ticks: { callback: ( v ) => yen( v ) },
						},
					},
				},
			} );
		}

		const quantityCanvas = getCanvas( root, 'service_quantity' );
		if ( data.service_quantity && quantityCanvas ) {
			new window.Chart( quantityCanvas, {
				type: 'pie',
				data: {
					labels: data.service_quantity.labels,
					datasets: [ {
						data: data.service_quantity.data,
						backgroundColor: [
							'rgba(22, 163, 74, 0.8)',
							'rgba(5, 150, 105, 0.8)',
							'rgba(4, 120, 87, 0.8)',
						],
						borderWidth: 0,
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { position: 'bottom' } },
				},
			} );
		}
	}

	function initSupplierCharts( root, data ) {
		const skillsCanvas = getCanvas( root, 'supplier_skills' );
		if ( data.supplier_skills && skillsCanvas ) {
			new window.Chart( skillsCanvas, {
				type: 'bar',
				data: {
					labels: data.supplier_skills.labels,
					datasets: [ {
						data: data.supplier_skills.data,
						backgroundColor: 'rgba(245, 158, 11, 0.75)',
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					indexAxis: 'y',
					plugins: { legend: { display: false } },
					scales: {
						x: {
							beginAtZero: true,
							ticks: { callback: ( v ) => yen( v ) },
						},
					},
				},
			} );
		}

		const countCanvas = getCanvas( root, 'skill_suppliers' );
		if ( data.skill_suppliers && countCanvas ) {
			new window.Chart( countCanvas, {
				type: 'bar',
				data: {
					labels: data.skill_suppliers.labels,
					datasets: [ {
						data: data.skill_suppliers.data,
						backgroundColor: 'rgba(14, 165, 233, 0.75)',
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						y: { beginAtZero: true, ticks: { stepSize: 1 } },
					},
				},
			} );
		}
	}

	function initProgressCharts( root, data ) {
		const barCanvas = getCanvas( root, 'progress_bar' );
		if ( data.progress_bar && barCanvas ) {
			new window.Chart( barCanvas, {
				type: 'bar',
				data: {
					labels: data.progress_bar.labels,
					datasets: [ {
						label: i18n.count || '件数',
						data: data.progress_bar.data,
						backgroundColor: 'rgba(79, 70, 229, 0.75)',
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { display: false } },
					scales: {
						y: { beginAtZero: true, ticks: { stepSize: 1 } },
					},
				},
			} );
		}

		const shareCanvas = getCanvas( root, 'progress_share' );
		if ( data.progress_share && shareCanvas && data.progress_share.labels.length > 0 ) {
			new window.Chart( shareCanvas, {
				type: 'doughnut',
				data: {
					labels: data.progress_share.labels,
					datasets: [ {
						data: data.progress_share.data,
						borderWidth: 0,
						backgroundColor: [
							'rgba(79, 70, 229, 0.85)',
							'rgba(99, 102, 241, 0.85)',
							'rgba(52, 211, 153, 0.85)',
							'rgba(148, 163, 184, 0.85)',
						],
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					plugins: { legend: { position: 'bottom' } },
				},
			} );
		}
	}

	function initStaffCharts( root, data ) {
		const staffCanvas = getCanvas( root, 'staff_sales' );
		if ( data.staff_sales && staffCanvas && data.staff_sales.labels.length > 0 ) {
			new window.Chart( staffCanvas, {
				type: 'bar',
				data: {
					labels: data.staff_sales.labels,
					datasets: [ {
						data: data.staff_sales.data,
						backgroundColor: 'rgba(79, 70, 229, 0.75)',
					} ],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					indexAxis: 'y',
					plugins: { legend: { display: false } },
					scales: {
						x: {
							beginAtZero: true,
							ticks: { callback: ( v ) => yen( v ) },
						},
					},
				},
			} );
		}
	}

	function initReportRoot( root ) {
		const payload = parsePayload( root );
		if ( ! payload || ! payload.chart_data || typeof window.Chart === 'undefined' ) {
			return;
		}

		const type = payload.type;
		const data = payload.chart_data;

		if ( type === 'sales' ) {
			initSalesCharts( root, data );
		} else if ( type === 'client' ) {
			initClientCharts( root, data );
		} else if ( type === 'service' ) {
			initServiceCharts( root, data );
		} else if ( type === 'supplier' ) {
			initSupplierCharts( root, data );
		} else if ( type === 'progress' ) {
			initProgressCharts( root, data );
		} else if ( type === 'staff_contribution' ) {
			initStaffCharts( root, data );
		}
	}

	function initAllReports() {
		document.querySelectorAll( '.kantanbond-report-root' ).forEach( initReportRoot );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAllReports );
	} else {
		initAllReports();
	}
} )();
