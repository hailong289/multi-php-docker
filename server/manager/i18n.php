<?php

declare(strict_types=1);

function manager_supported_locales(): array
{
    return ['vi', 'en'];
}

function manager_detect_locale(string $acceptLanguage): string
{
    return preg_match('/^\s*vi(?:[-_,;]|$)/i', $acceptLanguage) === 1 ? 'vi' : 'en';
}

function manager_locale(array &$session, string $acceptLanguage): string
{
    $locale = (string) ($session['locale'] ?? '');
    if (in_array($locale, manager_supported_locales(), true)) {
        return $locale;
    }

    $locale = manager_detect_locale($acceptLanguage);
    $session['locale'] = $locale;
    return $locale;
}

function manager_translations(): array
{
    return [
        'en' => [
            'page.title' => 'Multi-PHP Server Manager',
            'header.title' => 'Server Manager',
            'header.subtitle' => 'Manage local Nginx virtual servers stored in env.json.',
            'header.local_only' => 'Local access only · 127.0.0.1:8080',
            'language.label' => 'Language',
            'theme.label' => 'Theme',
            'theme.system' => 'System',
            'theme.light' => 'Light',
            'theme.dark' => 'Dark',
            'php.default' => 'default',
            'servers.title' => 'Configured servers',
            'servers.empty' => 'No servers configured yet.',
            'table.app_domain' => 'App / domain',
            'table.php' => 'PHP',
            'table.document_root' => 'Document root',
            'table.actions' => 'Actions',
            'action.edit' => 'Edit',
            'action.delete' => 'Delete',
            'action.cancel' => 'Cancel',
            'confirm.delete' => 'Delete this server?',
            'form.add_title' => 'Add server',
            'form.edit_title' => 'Edit server',
            'form.app_name' => 'Application name',
            'form.app_placeholder' => 'my-app',
            'form.domain' => 'Local domain',
            'form.domain_placeholder' => 'my-app.test',
            'form.php_version' => 'PHP version',
            'form.server_path' => 'Document root in container',
            'form.path_placeholder' => '/var/www/source_php8.2/my-app/public',
            'form.add' => 'Add server',
            'form.save' => 'Save changes',
            'reload.button' => 'Apply & Reload Nginx',
            'reload.success' => 'Success',
            'reload.error' => 'Error',
            'reload.unknown' => 'Unknown reload result.',
            'reload.requested' => 'Nginx reload requested. Refresh this page in a moment to see the result.',
            'reload.status.generated' => 'Nginx templates were generated and reloaded successfully.',
            'reload.status.generate_failed' => 'Could not generate Nginx templates. See runtime/nginx.reload.log.',
            'reload.status.invalid' => 'Nginx configuration is invalid. Previous configuration was restored.',
            'reload.status.failed' => 'Nginx could not reload. Previous configuration was restored.',
            'flash.deleted' => 'Server deleted. Reload Nginx to apply the change.',
            'flash.added' => 'Server added. Reload Nginx to apply the change.',
            'flash.updated' => 'Server updated. Reload Nginx to apply the change.',
            'error.invalid_csrf' => 'Invalid CSRF token. Refresh the page and try again.',
            'error.runtime_directory' => 'Unable to create the runtime directory.',
            'error.reload_request' => 'Unable to send the Nginx reload request.',
            'error.server_missing' => 'The selected server no longer exists.',
            'error.env_missing' => 'env.json does not exist or is not readable.',
            'error.env_read' => 'Unable to read env.json.',
            'error.env_object' => 'env.json must contain a JSON object.',
            'error.env_open' => 'Unable to open env.json for writing.',
            'error.env_lock' => 'Unable to lock env.json.',
            'error.env_prepare' => 'Unable to prepare env.json for writing.',
            'error.env_write' => 'Unable to write the complete env.json file.',
            'validation.app_name' => 'Use 1-64 letters, numbers, dots, underscores, or hyphens.',
            'validation.domain' => 'Enter a valid hostname, for example my-app.test.',
            'validation.php_version' => 'Select a supported PHP version.',
            'validation.safe_path' => 'Use a safe path inside :prefix without spaces or special characters.',
            'validation.duplicate_app' => 'This application name already exists.',
            'validation.duplicate_domain' => 'This domain already exists.',
            'php_controller.title' => 'PHP Versions',
            'php_controller.subtitle' => 'Start, stop, or restart existing PHP containers.',
            'php_controller.version' => 'Version',
            'php_controller.container' => 'Container',
            'php_controller.profile' => 'Profile',
            'php_controller.state' => 'State',
            'php_controller.actions' => 'Actions',
            'php_controller.default_profile' => 'Default',
            'php_controller.start' => 'Start',
            'php_controller.stop' => 'Stop',
            'php_controller.restart' => 'Restart',
            'php_controller.state_running' => 'Running',
            'php_controller.state_stopped' => 'Stopped',
            'php_controller.state_not_created' => 'Not created',
            'php_controller.state_busy' => 'Processing',
            'php_controller.state_error' => 'Error',
            'php_controller.requested' => ':action requested for :version. Refresh the page in a moment.',
            'php_controller.invalid_service' => 'The selected PHP service is not allowed.',
            'php_controller.invalid_action' => 'The selected container action is not allowed.',
            'php_controller.request_failed' => 'Unable to send the PHP container request.',
            'php_controller.invalid_request' => 'The controller rejected an invalid request.',
            'php_controller.processing' => 'The requested container action is being processed.',
            'php_controller.action_success' => 'The last container action completed successfully.',
            'php_controller.action_failed' => 'The last container action failed.',
            'php_controller.status_refreshed' => 'Container state refreshed.',
            'php_controller.status_unavailable' => 'Controller status is not available yet.',
            'php_controller.create_hint' => 'Create this optional container once, then refresh the page:',
        ],
        'vi' => [
            'page.title' => 'Trình quản lý máy chủ Multi-PHP',
            'header.title' => 'Quản lý máy chủ',
            'header.subtitle' => 'Quản lý máy chủ ảo Nginx cục bộ được lưu trong env.json.',
            'header.local_only' => 'Chỉ truy cập cục bộ · 127.0.0.1:8080',
            'language.label' => 'Ngôn ngữ',
            'theme.label' => 'Giao diện',
            'theme.system' => 'Hệ thống',
            'theme.light' => 'Sáng',
            'theme.dark' => 'Tối',
            'php.default' => 'mặc định',
            'servers.title' => 'Máy chủ đã cấu hình',
            'servers.empty' => 'Chưa có máy chủ nào được cấu hình.',
            'table.app_domain' => 'Ứng dụng / tên miền',
            'table.php' => 'PHP',
            'table.document_root' => 'Thư mục gốc',
            'table.actions' => 'Thao tác',
            'action.edit' => 'Sửa',
            'action.delete' => 'Xóa',
            'action.cancel' => 'Hủy',
            'confirm.delete' => 'Bạn có chắc muốn xóa máy chủ này?',
            'form.add_title' => 'Thêm máy chủ',
            'form.edit_title' => 'Sửa máy chủ',
            'form.app_name' => 'Tên ứng dụng',
            'form.app_placeholder' => 'ung-dung-cua-toi',
            'form.domain' => 'Tên miền local',
            'form.domain_placeholder' => 'ung-dung.test',
            'form.php_version' => 'Phiên bản PHP',
            'form.server_path' => 'Document root trong container',
            'form.path_placeholder' => '/var/www/source_php8.2/ung-dung/public',
            'form.add' => 'Thêm máy chủ',
            'form.save' => 'Lưu thay đổi',
            'reload.button' => 'Áp dụng & tải lại Nginx',
            'reload.success' => 'Thành công',
            'reload.error' => 'Lỗi',
            'reload.unknown' => 'Không xác định được kết quả tải lại.',
            'reload.requested' => 'Đã gửi yêu cầu tải lại Nginx. Hãy làm mới trang sau giây lát để xem kết quả.',
            'reload.status.generated' => 'Đã tạo template và tải lại Nginx thành công.',
            'reload.status.generate_failed' => 'Không thể tạo template Nginx. Xem runtime/nginx.reload.log.',
            'reload.status.invalid' => 'Cấu hình Nginx không hợp lệ. Đã khôi phục cấu hình trước đó.',
            'reload.status.failed' => 'Không thể tải lại Nginx. Đã khôi phục cấu hình trước đó.',
            'flash.deleted' => 'Đã xóa máy chủ. Hãy tải lại Nginx để áp dụng thay đổi.',
            'flash.added' => 'Đã thêm máy chủ. Hãy tải lại Nginx để áp dụng thay đổi.',
            'flash.updated' => 'Đã cập nhật máy chủ. Hãy tải lại Nginx để áp dụng thay đổi.',
            'error.invalid_csrf' => 'CSRF token không hợp lệ. Hãy làm mới trang và thử lại.',
            'error.runtime_directory' => 'Không thể tạo thư mục runtime.',
            'error.reload_request' => 'Không thể gửi yêu cầu tải lại Nginx.',
            'error.server_missing' => 'Máy chủ đã chọn không còn tồn tại.',
            'error.env_missing' => 'env.json không tồn tại hoặc không thể đọc.',
            'error.env_read' => 'Không thể đọc env.json.',
            'error.env_object' => 'env.json phải chứa một JSON object.',
            'error.env_open' => 'Không thể mở env.json để ghi.',
            'error.env_lock' => 'Không thể khóa env.json.',
            'error.env_prepare' => 'Không thể chuẩn bị env.json để ghi.',
            'error.env_write' => 'Không thể ghi đầy đủ file env.json.',
            'validation.app_name' => 'Dùng 1-64 chữ cái, chữ số, dấu chấm, gạch dưới hoặc gạch ngang.',
            'validation.domain' => 'Nhập hostname hợp lệ, ví dụ ung-dung.test.',
            'validation.php_version' => 'Chọn một phiên bản PHP được hỗ trợ.',
            'validation.safe_path' => 'Dùng đường dẫn an toàn bên trong :prefix, không chứa khoảng trắng hoặc ký tự đặc biệt.',
            'validation.duplicate_app' => 'Tên ứng dụng này đã tồn tại.',
            'validation.duplicate_domain' => 'Tên miền này đã tồn tại.',
            'php_controller.title' => 'Các phiên bản PHP',
            'php_controller.subtitle' => 'Khởi động, dừng hoặc khởi động lại các PHP container hiện có.',
            'php_controller.version' => 'Phiên bản',
            'php_controller.container' => 'Container',
            'php_controller.profile' => 'Profile',
            'php_controller.state' => 'Trạng thái',
            'php_controller.actions' => 'Thao tác',
            'php_controller.default_profile' => 'Mặc định',
            'php_controller.start' => 'Khởi động',
            'php_controller.stop' => 'Dừng',
            'php_controller.restart' => 'Khởi động lại',
            'php_controller.state_running' => 'Đang chạy',
            'php_controller.state_stopped' => 'Đã dừng',
            'php_controller.state_not_created' => 'Chưa được tạo',
            'php_controller.state_busy' => 'Đang xử lý',
            'php_controller.state_error' => 'Lỗi',
            'php_controller.requested' => 'Đã yêu cầu :action :version. Hãy làm mới trang sau giây lát.',
            'php_controller.invalid_service' => 'PHP service đã chọn không được phép.',
            'php_controller.invalid_action' => 'Thao tác container đã chọn không được phép.',
            'php_controller.request_failed' => 'Không thể gửi yêu cầu điều khiển PHP container.',
            'php_controller.invalid_request' => 'Controller đã từ chối một yêu cầu không hợp lệ.',
            'php_controller.processing' => 'Yêu cầu điều khiển container đang được xử lý.',
            'php_controller.action_success' => 'Thao tác container gần nhất đã hoàn tất thành công.',
            'php_controller.action_failed' => 'Thao tác container gần nhất đã thất bại.',
            'php_controller.status_refreshed' => 'Đã làm mới trạng thái container.',
            'php_controller.status_unavailable' => 'Chưa có trạng thái từ controller.',
            'php_controller.create_hint' => 'Tạo container tùy chọn này một lần rồi làm mới trang:',
        ],
    ];
}

function manager_translate(string $locale, string $key, array $parameters = []): string
{
    $translations = manager_translations();
    $message = $translations[$locale][$key] ?? $translations['en'][$key] ?? $key;
    foreach ($parameters as $name => $value) {
        $message = str_replace(':' . $name, (string) $value, $message);
    }
    return $message;
}
