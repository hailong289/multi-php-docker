/** Map vue-router route names to tour step builders. */
export function tourIdForRoute(routeName) {
  const map = {
    home: 'home',
    domains: 'domains',
    nginx: 'nginx',
    services: 'services',
    'compose-yaml': 'compose-yaml',
    'service-logs': 'service-logs',
    'php-versions': 'php-versions',
    'php-version-logs': 'php-version-logs',
    'php-version-detail': 'php-version-detail',
    'php-version-catalog': 'php-version-catalog',
    'php-version-supervisor': 'php-version-supervisor',
    'php-version-run': 'php-version-run',
  }
  return map[routeName] || null
}

/**
 * @param {(key: string, params?: object) => string} t
 * @returns {Record<string, Array<{ element?: string, prepareClick?: string, popover: object }>>}
 */
export function buildTourSteps(t) {
  return {
    home: [
      {
        element: '[data-tour="app-header"]',
        popover: {
          title: t('tour.home.welcome_title'),
          description: t('tour.home.welcome_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="app-nav"]',
        popover: {
          title: t('tour.home.nav_title'),
          description: t('tour.home.nav_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="home-add"]',
        popover: {
          title: t('tour.home.add_title'),
          description: t('tour.home.add_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="home-reload"]',
        popover: {
          title: t('tour.home.reload_title'),
          description: t('tour.home.reload_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="home-table"]',
        popover: {
          title: t('tour.home.table_title'),
          description: t('tour.home.table_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="tour-replay"]',
        popover: {
          title: t('tour.common.replay_title'),
          description: t('tour.common.replay_body'),
          side: 'bottom',
          align: 'end',
        },
      },
    ],
    domains: [
      {
        element: '[data-tour="domains-panel"]',
        popover: {
          title: t('tour.domains.intro_title'),
          description: t('tour.domains.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="domains-add"]',
        popover: {
          title: t('tour.domains.add_title'),
          description: t('tour.domains.add_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="domains-table"]',
        popover: {
          title: t('tour.domains.table_title'),
          description: t('tour.domains.table_body'),
          side: 'top',
          align: 'start',
        },
      },
    ],
    nginx: [
      {
        element: '[data-tour="nginx-panel"]',
        popover: {
          title: t('tour.nginx.intro_title'),
          description: t('tour.nginx.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="nginx-tabs"]',
        popover: {
          title: t('tour.nginx.tabs_title'),
          description: t('tour.nginx.tabs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="nginx-actions"]',
        prepareClick: '[data-tour="nginx-control-tab"]',
        popover: {
          title: t('tour.nginx.actions_title'),
          description: t('tour.nginx.actions_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="nginx-logs"]',
        prepareClick: '[data-tour="nginx-control-tab"]',
        popover: {
          title: t('tour.nginx.logs_title'),
          description: t('tour.nginx.logs_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="nginx-templates-tab"]',
        prepareClick: '[data-tour="nginx-templates-tab"]',
        popover: {
          title: t('tour.nginx.templates_title'),
          description: t('tour.nginx.templates_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="nginx-domain-logs-tab"]',
        prepareClick: '[data-tour="nginx-domain-logs-tab"]',
        popover: {
          title: t('tour.nginx.domain_logs_title'),
          description: t('tour.nginx.domain_logs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
    ],
    services: [
      {
        element: '[data-tour="services-panel"]',
        popover: {
          title: t('tour.services.intro_title'),
          description: t('tour.services.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="services-compose-yaml"]',
        popover: {
          title: t('tour.services.compose_title'),
          description: t('tour.services.compose_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="services-table"]',
        popover: {
          title: t('tour.services.table_title'),
          description: t('tour.services.table_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="services-logs-btn"]',
        popover: {
          title: t('tour.services.logs_title'),
          description: t('tour.services.logs_body'),
          side: 'left',
          align: 'start',
        },
      },
    ],
    'compose-yaml': [
      {
        element: '[data-tour="compose-panel"]',
        popover: {
          title: t('tour.compose.intro_title'),
          description: t('tour.compose.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="compose-add"]',
        popover: {
          title: t('tour.compose.add_title'),
          description: t('tour.compose.add_body'),
          side: 'left',
          align: 'start',
        },
      },
      {
        element: '[data-tour="compose-body"]',
        popover: {
          title: t('tour.compose.list_title'),
          description: t('tour.compose.list_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="compose-editor"]',
        popover: {
          title: t('tour.compose.editor_title'),
          description: t('tour.compose.editor_body'),
          side: 'top',
          align: 'start',
        },
      },
    ],
    'service-logs': [
      {
        element: '[data-tour="service-logs-panel"]',
        popover: {
          title: t('tour.services.logs_title'),
          description: t('tour.services.logs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
    ],
    'php-versions': [
      {
        element: '[data-tour="php-panel"]',
        popover: {
          title: t('tour.php.intro_title'),
          description: t('tour.php.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-add"]',
        popover: {
          title: t('tour.php.add_title'),
          description: t('tour.php.add_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="php-table"]',
        popover: {
          title: t('tour.php.table_title'),
          description: t('tour.php.table_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-logs-btn"]',
        popover: {
          title: t('tour.php.logs_title'),
          description: t('tour.php.logs_body'),
          side: 'left',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-run"]',
        popover: {
          title: t('tour.php.run_title'),
          description: t('tour.php.run_body'),
          side: 'left',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-supervisor"]',
        popover: {
          title: t('tour.php.supervisor_title'),
          description: t('tour.php.supervisor_body'),
          side: 'left',
          align: 'start',
        },
      },
    ],
    'php-version-logs': [
      {
        element: '[data-tour="service-logs-panel"]',
        popover: {
          title: t('tour.php.logs_title'),
          description: t('tour.php.logs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
    ],
    'php-version-detail': [
      {
        element: '[data-tour="php-detail-panel"]',
        popover: {
          title: t('tour.php_detail.intro_title'),
          description: t('tour.php_detail.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-detail-actions"]',
        popover: {
          title: t('tour.php_detail.actions_title'),
          description: t('tour.php_detail.actions_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="php-run"]',
        popover: {
          title: t('tour.php.run_title'),
          description: t('tour.php.run_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="php-detail-tabs"]',
        popover: {
          title: t('tour.php_detail.tabs_title'),
          description: t('tour.php_detail.tabs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-detail-ini-tab"]',
        popover: {
          title: t('tour.php_detail.ini_title'),
          description: t('tour.php_detail.ini_body'),
          side: 'bottom',
          align: 'start',
        },
      },
    ],
    'php-version-catalog': [
      {
        element: '[data-tour="php-catalog-panel"]',
        popover: {
          title: t('tour.php_catalog.intro_title'),
          description: t('tour.php_catalog.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-catalog-filters"]',
        popover: {
          title: t('tour.php_catalog.filters_title'),
          description: t('tour.php_catalog.filters_body'),
          side: 'bottom',
          align: 'start',
        },
      },
    ],
    'php-version-supervisor': [
      {
        element: '[data-tour="supervisor-panel"]',
        popover: {
          title: t('tour.supervisor.intro_title'),
          description: t('tour.supervisor.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="supervisor-tabs"]',
        popover: {
          title: t('tour.supervisor.tabs_title'),
          description: t('tour.supervisor.tabs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="supervisor-actions"]',
        prepareClick: '[data-tour="supervisor-control-tab"]',
        popover: {
          title: t('tour.supervisor.actions_title'),
          description: t('tour.supervisor.actions_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="supervisor-logs"]',
        prepareClick: '[data-tour="supervisor-control-tab"]',
        popover: {
          title: t('tour.supervisor.logs_title'),
          description: t('tour.supervisor.logs_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="supervisor-configs-tab"]',
        prepareClick: '[data-tour="supervisor-configs-tab"]',
        popover: {
          title: t('tour.supervisor.configs_title'),
          description: t('tour.supervisor.configs_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="supervisor-conf-add"]',
        prepareClick: '[data-tour="supervisor-configs-tab"]',
        popover: {
          title: t('tour.supervisor.conf_add_title'),
          description: t('tour.supervisor.conf_add_body'),
          side: 'left',
          align: 'start',
        },
      },
      {
        element: '[data-tour="supervisor-configs"]',
        prepareClick: '[data-tour="supervisor-configs-tab"]',
        popover: {
          title: t('tour.supervisor.conf_editor_title'),
          description: t('tour.supervisor.conf_editor_body'),
          side: 'top',
          align: 'start',
        },
      },
    ],
    'php-version-run': [
      {
        element: '[data-tour="php-run-panel"]',
        popover: {
          title: t('tour.php_run.intro_title'),
          description: t('tour.php_run.intro_body'),
          side: 'bottom',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-run-session-add"]',
        popover: {
          title: t('tour.php_run.add_title'),
          description: t('tour.php_run.add_body'),
          side: 'bottom',
          align: 'end',
        },
      },
      {
        element: '[data-tour="php-run-sessions"]',
        popover: {
          title: t('tour.php_run.sessions_title'),
          description: t('tour.php_run.sessions_body'),
          side: 'right',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-run-editor"]',
        popover: {
          title: t('tour.php_run.editor_title'),
          description: t('tour.php_run.editor_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-run-actions"]',
        popover: {
          title: t('tour.php_run.actions_title'),
          description: t('tour.php_run.actions_body'),
          side: 'top',
          align: 'start',
        },
      },
      {
        element: '[data-tour="php-run-output"]',
        popover: {
          title: t('tour.php_run.output_title'),
          description: t('tour.php_run.output_body'),
          side: 'top',
          align: 'start',
        },
      },
    ],
  }
}
