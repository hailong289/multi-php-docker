export function buildPinMenuItem(kind, id, { t, pinned, toggle }) {
  return {
    id: 'pin',
    label: pinned ? t('pin.from_home') : t('pin.to_home'),
    run: () => toggle(kind, id),
  }
}

export function buildInfraMenuItems(service, { t, router, mgr }) {
  const {
    infraActionEnabled,
    infraAction,
    infraServiceState,
    isPending,
  } = mgr
  return [
    {
      id: 'create',
      label: t('services.create'),
      primary: true,
      disabled: !infraActionEnabled(service, 'create'),
      loading: isPending('infra', { service, action: 'create' }),
      run: () => infraAction(service, 'create'),
    },
    {
      id: 'start',
      label: t('services.start'),
      disabled: !infraActionEnabled(service, 'start'),
      loading: isPending('infra', { service, action: 'start' }),
      run: () => infraAction(service, 'start'),
    },
    {
      id: 'stop',
      label: t('services.stop'),
      disabled: !infraActionEnabled(service, 'stop'),
      loading: isPending('infra', { service, action: 'stop' }),
      run: () => infraAction(service, 'stop'),
    },
    {
      id: 'restart',
      label: t('services.restart'),
      disabled: !infraActionEnabled(service, 'restart'),
      loading: isPending('infra', { service, action: 'restart' }),
      run: () => infraAction(service, 'restart'),
    },
    {
      id: 'logs',
      label: t('services.view_logs'),
      disabled: infraServiceState(service) === 'not_created',
      run: () => router.push({ name: 'service-logs', params: { service } }),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      disabled: !infraActionEnabled(service, 'delete'),
      loading: isPending('infra', { service, action: 'delete' }),
      run: () => infraAction(service, 'delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      disabled: !infraActionEnabled(service, 'delete-image'),
      loading: isPending('infra', { service, action: 'delete-image' }),
      run: () => infraAction(service, 'delete-image'),
    },
  ]
}

export function buildComposeMenuItems(item, { t, router, mgr }) {
  const {
    composeYamlActionEnabled,
    composeYamlAction,
    composeFileState,
    isPending,
  } = mgr
  return [
    {
      id: 'create',
      label: t('services.create'),
      primary: true,
      disabled: !composeYamlActionEnabled(item, 'create'),
      loading: isPending('compose-file', { name: item.name, action: 'create' }),
      run: () => composeYamlAction(item, 'create'),
    },
    {
      id: 'start',
      label: t('services.start'),
      disabled: !composeYamlActionEnabled(item, 'start'),
      loading: isPending('compose-file', { name: item.name, action: 'start' }),
      run: () => composeYamlAction(item, 'start'),
    },
    {
      id: 'stop',
      label: t('services.stop'),
      disabled: !composeYamlActionEnabled(item, 'stop'),
      loading: isPending('compose-file', { name: item.name, action: 'stop' }),
      run: () => composeYamlAction(item, 'stop'),
    },
    {
      id: 'restart',
      label: t('services.restart'),
      disabled: !composeYamlActionEnabled(item, 'restart'),
      loading: isPending('compose-file', { name: item.name, action: 'restart' }),
      run: () => composeYamlAction(item, 'restart'),
    },
    {
      id: 'logs',
      label: t('services.view_logs'),
      disabled: composeFileState(item) === 'not_created',
      run: () => router.push({ name: 'compose-file-logs', params: { name: item.name } }),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      disabled: !composeYamlActionEnabled(item, 'delete'),
      loading: isPending('compose-file', { name: item.name, action: 'delete' }),
      run: () => composeYamlAction(item, 'delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      disabled: !composeYamlActionEnabled(item, 'delete-image'),
      loading: isPending('compose-file', { name: item.name, action: 'delete-image' }),
      run: () => composeYamlAction(item, 'delete-image'),
    },
  ]
}

export function buildPhpMenuItems(service, { t, router, mgr }) {
  const { phpActionEnabled, phpAction, phpServiceState, isPending } = mgr
  return [
    {
      id: 'create',
      label: t('php_controller.create'),
      primary: true,
      disabled: !phpActionEnabled(service, 'create'),
      loading: isPending('php', { service, action: 'create' }),
      run: () => phpAction(service, 'create'),
    },
    {
      id: 'start',
      label: t('php_controller.start'),
      disabled: !phpActionEnabled(service, 'start'),
      loading: isPending('php', { service, action: 'start' }),
      run: () => phpAction(service, 'start'),
    },
    {
      id: 'stop',
      label: t('php_controller.stop'),
      disabled: !phpActionEnabled(service, 'stop'),
      loading: isPending('php', { service, action: 'stop' }),
      run: () => phpAction(service, 'stop'),
    },
    {
      id: 'restart',
      label: t('php_controller.restart'),
      disabled: !phpActionEnabled(service, 'restart'),
      loading: isPending('php', { service, action: 'restart' }),
      run: () => phpAction(service, 'restart'),
    },
    {
      id: 'logs',
      label: t('php_controller.view_logs'),
      disabled: phpServiceState(service) === 'not_created',
      run: () => router.push({ name: 'php-version-logs', params: { service } }),
    },
    {
      id: 'run',
      label: t('php_controller.run'),
      run: () => router.push({ name: 'php-version-run', params: { service } }),
    },
    {
      id: 'details',
      label: t('php_controller.details'),
      run: () => router.push({ name: 'php-version-detail', params: { service } }),
    },
    {
      id: 'supervisor',
      label: t('php_controller.supervisor'),
      run: () => router.push({ name: 'php-version-supervisor', params: { service } }),
    },
    {
      id: 'delete',
      label: t('services.delete_container'),
      danger: true,
      disabled: !phpActionEnabled(service, 'delete'),
      loading: isPending('php', { service, action: 'delete' }),
      run: () => phpAction(service, 'delete'),
    },
    {
      id: 'delete-image',
      label: t('services.delete_image'),
      danger: true,
      disabled: !phpActionEnabled(service, 'delete-image'),
      loading: isPending('php', { service, action: 'delete-image' }),
      run: () => phpAction(service, 'delete-image'),
    },
  ]
}

export function buildNginxMenuItems({ t, nginx }) {
  const { enabled, pending, run } = nginx
  return [
    {
      id: 'start',
      label: t('nginx.start'),
      primary: true,
      disabled: !enabled('start'),
      loading: pending('start'),
      run: () => run('start'),
    },
    {
      id: 'stop',
      label: t('nginx.stop'),
      disabled: !enabled('stop'),
      loading: pending('stop'),
      run: () => run('stop'),
    },
    {
      id: 'restart',
      label: t('nginx.restart'),
      disabled: !enabled('restart'),
      loading: pending('restart'),
      run: () => run('restart'),
    },
    {
      id: 'test',
      label: t('nginx.test'),
      disabled: !enabled('test'),
      loading: pending('test'),
      run: () => run('test'),
    },
    {
      id: 'reload',
      label: t('nginx.apply_reload'),
      disabled: !enabled('reload'),
      loading: pending('reload'),
      run: () => run('reload'),
    },
  ]
}
