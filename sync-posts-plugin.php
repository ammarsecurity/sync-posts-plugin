<?php
/*
Plugin Name: مزامنة المقالات في الوقت الفعلي
Plugin URI: https://www.linkedin.com/in/ammar-alasfer-b2933415a/
Description: مزامنة تلقائية للمقالات والصور المميزة بين موقعين ووردبريس باستخدام REST API
Version: 1.0
Author: عمار الأصفر
Author URI: https://www.linkedin.com/in/ammar-alasfer-b2933415a/
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: sync-posts-plugin
Domain Path: /languages
*/

if (!defined('ABSPATH')) {
    exit;
}

function sync_log_error($message) {
    $log_file = plugin_dir_path(__FILE__) . 'sync_error_log.txt';
    $current_time = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$current_time] $message\n", FILE_APPEND);
}

add_action('admin_menu', function() {
    add_menu_page(
        'إعدادات مزامنة المقالات',
        'مزامنة المقالات',
        'manage_options',
        'sync-posts-settings',
        'sync_posts_settings_page',
        'dashicons-update',
        100
    );
});

function fetch_target_site_categories($site_url, $username, $password) {
    $categories_url = rtrim($site_url, '/') . '/wp-json/wp/v2/categories?per_page=100';
    $auth = base64_encode($username . ':' . $password);

    $response = wp_remote_get($categories_url, [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ],
    ]);

    if (is_wp_error($response)) {
        return [];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($body)) {
        return [];
    }

    $categories = [];
    foreach ($body as $category) {
        $categories[] = [
            'id' => $category['id'],
            'name' => $category['name'],
        ];
    }

    return $categories;
}

function sync_posts_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    if (isset($_POST['sync_posts_settings_nonce']) && wp_verify_nonce($_POST['sync_posts_settings_nonce'], 'sync_posts_save_settings')) {
        update_option('sync_target_site', sanitize_text_field($_POST['sync_target_site']));
        update_option('sync_target_user', sanitize_text_field($_POST['sync_target_user']));
        update_option('sync_target_password', sanitize_text_field($_POST['sync_target_password']));
        update_option('sync_target_category', sanitize_text_field($_POST['sync_target_category']));
        
        // اختبار الاتصال والصلاحيات مباشرة عند حفظ الإعدادات
        $target_site = sanitize_text_field($_POST['sync_target_site']);
        $target_user = sanitize_text_field($_POST['sync_target_user']);
        $target_password = sanitize_text_field($_POST['sync_target_password']);
        
        $auth_test_result = test_target_site_auth($target_site, $target_user, $target_password);
        
        if ($auth_test_result === true) {
            echo '<div class="updated"><p>✅ تم حفظ الإعدادات بنجاح! تم التحقق من الاتصال بالموقع الهدف.</p></div>';
        } else {
            echo '<div class="error"><p>⚠️ تم حفظ الإعدادات ولكن فشل اختبار الاتصال: ' . esc_html($auth_test_result) . '</p></div>';
        }
    }

    $target_site = get_option('sync_target_site', '');
    $target_user = get_option('sync_target_user', '');
    $target_password = get_option('sync_target_password', '');
    $target_category = get_option('sync_target_category', '');

    $categories = [];
    if (!empty($target_site) && !empty($target_user) && !empty($target_password)) {
        $categories = fetch_target_site_categories($target_site, $target_user, $target_password);
    }
?>
<div class="wrap">
    <h1>إعدادات مزامنة المقالات</h1>
    <form method="post">
        <?php wp_nonce_field('sync_posts_save_settings', 'sync_posts_settings_nonce'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">رابط الموقع الهدف</th>
                <td><input type="text" name="sync_target_site" value="<?php echo esc_attr($target_site); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">اسم المستخدم</th>
                <td><input type="text" name="sync_target_user" value="<?php echo esc_attr($target_user); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">كلمة مرور التطبيق</th>
                <td><input type="password" name="sync_target_password" value="<?php echo esc_attr($target_password); ?>" class="regular-text" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">اختيار التصنيف الافتراضي</th>
                <td>
                    <select name="sync_target_category" class="regular-text">
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo esc_attr($cat['id']); ?>" <?php selected($target_category, $cat['id']); ?>>
                                <?php echo esc_html($cat['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>
        <?php submit_button('حفظ الإعدادات'); ?>
    </form>

    <hr>
    <h2>اختبار الاتصال</h2>
    <button id="test-connection-btn" class="button button-secondary">اختبار الاتصال بالموقع الهدف</button>
    <div id="connection-test-result" style="margin-top: 10px; padding: 10px; display: none;"></div>
    
    <hr>
    <h2>مزامنة المقالات الموجودة</h2>
    <button id="start-sync-btn" class="button button-primary">مزامنة المقالات الموجودة</button>

    <form method="post" style="margin-top: 20px;">
        <?php wp_nonce_field('view_sync_log', 'view_sync_log_nonce'); ?>
        <input type="submit" name="view_sync_log" class="button button-secondary" value="عرض آخر الأخطاء">
    </form>
    
    <form method="post" style="margin-top: 10px;">
        <?php wp_nonce_field('clear_sync_log', 'clear_sync_log_nonce'); ?>
        <input type="submit" name="clear_sync_log" class="button button-secondary" value="مسح سجل الأخطاء">
    </form>

    <div id="progress-container" style="margin-top: 20px; display: none;">
        <div style="background: #e0e0e0; width: 100%; height: 20px; border-radius: 5px; overflow: hidden;">
            <div id="progress-bar" style="background: #0073aa; width: 0%; height: 100%;"></div>
        </div>
        <p id="progress-text" style="margin-top: 5px; font-weight: bold;">0% مكتمل</p>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // زر اختبار الاتصال
        $('#test-connection-btn').on('click', function() {
            var button = $(this);
            var resultDiv = $('#connection-test-result');
            
            button.prop('disabled', true).text('جاري الاختبار...');
            resultDiv.html('<p>جاري اختبار الاتصال...</p>').show();
            
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'test_target_connection'
                },
                success: function(response) {
                    button.prop('disabled', false).text('اختبار الاتصال بالموقع الهدف');
                    
                    if (response.success) {
                        resultDiv.html('<div class="notice notice-success"><p>✅ ' + response.data.message + '</p></div>');
                    } else {
                        resultDiv.html('<div class="notice notice-error"><p>❌ ' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    button.prop('disabled', false).text('اختبار الاتصال بالموقع الهدف');
                    resultDiv.html('<div class="notice notice-error"><p>❌ خطأ في الاتصال مع ووردبريس</p></div>');
                }
            });
        });
        
        // زر مزامنة المقالات
        $('#start-sync-btn').on('click', function() {
            var button = $(this);
            button.prop('disabled', true).text('جاري المزامنة...');
            $('#progress-container').show();
            
            // الحصول على قائمة المقالات
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'sync_existing_posts_batch'
                },
                success: function(response) {
                    if (response.success) {
                        var posts = response.data.posts;
                        var total = response.data.total;
                        var processed = 0;
                        
                        processPosts(posts, processed, total, button);
                    } else {
                        button.prop('disabled', false).text('مزامنة المقالات الموجودة');
                        alert('حدث خطأ أثناء جلب المقالات: ' + (response.data ? response.data : ''));
                    }
                },
                error: function() {
                    button.prop('disabled', false).text('مزامنة المقالات الموجودة');
                    alert('حدث خطأ في الاتصال');
                }
            });
        });
        
        function processPosts(posts, processed, total, button) {
            if (posts.length === 0 || processed >= total) {
                button.prop('disabled', false).text('مزامنة المقالات الموجودة');
                $('#progress-text').text('100% مكتمل');
                $('#progress-bar').css('width', '100%');
                alert('تمت المزامنة بنجاح');
                return;
            }
            
            var postId = posts.shift();
            
            $.ajax({
                url: ajaxurl,
                data: {
                    action: 'sync_single_post',
                    post_id: postId
                },
                success: function() {
                    processed++;
                    var progress = Math.round((processed / total) * 100);
                    $('#progress-bar').css('width', progress + '%');
                    $('#progress-text').text(progress + '% مكتمل');
                    
                    // المتابعة مع المقالة التالية
                    processPosts(posts, processed, total, button);
                },
                error: function() {
                    processed++;
                    var progress = Math.round((processed / total) * 100);
                    $('#progress-bar').css('width', progress + '%');
                    $('#progress-text').text(progress + '% مكتمل');
                    
                    // المتابعة رغم الخطأ
                    processPosts(posts, processed, total, button);
                }
            });
        }
    });
    </script>
</div>
<?php
    if (isset($_POST['view_sync_log']) && isset($_POST['view_sync_log_nonce']) && wp_verify_nonce($_POST['view_sync_log_nonce'], 'view_sync_log')) {
        $log_file = plugin_dir_path(__FILE__) . 'sync_error_log.txt';
        if (file_exists($log_file)) {
            echo '<div style="margin-top: 20px; background: #fff; padding: 20px; border: 1px solid #ccc;">';
            echo '<h3>📄 آخر سجل أخطاء:</h3>';
            echo '<pre style="max-height: 400px; overflow: auto; background: #f7f7f7; padding: 10px;">';
            echo esc_html(file_get_contents($log_file));
            echo '</pre>';
            echo '</div>';
            
            // إضافة شرح للأخطاء الشائعة
            echo '<div style="margin-top: 20px; background: #f9f9f9; padding: 15px; border: 1px solid #e5e5e5;">';
            echo '<h3>🔍 تشخيص الأخطاء الشائعة:</h3>';
            echo '<ul style="list-style-type: disc; margin-left: 20px;">';
            echo '<li><strong>HTTP Status 401:</strong> خطأ في المصادقة. تأكد من صحة اسم المستخدم وكلمة مرور التطبيق.</li>';
            echo '<li><strong>rest_cannot_create:</strong> المستخدم لا يملك صلاحيات كافية. تأكد من أن المستخدم له دور "محرر" أو "مدير" وليس "مساهم".</li>';
            echo '<li><strong>Failed to upload featured image:</strong> تعذر رفع الصورة المميزة. تأكد من إمكانية الوصول إلى الصور وأن المستخدم يملك صلاحيات رفع الوسائط.</li>';
            echo '</ul>';
            echo '</div>';
        } else {
            echo '<div class="notice notice-warning"><p>لا يوجد سجل أخطاء حاليا ✅</p></div>';
        }
    }
    
    // مسح سجل الأخطاء
    if (isset($_POST['clear_sync_log']) && isset($_POST['clear_sync_log_nonce']) && wp_verify_nonce($_POST['clear_sync_log_nonce'], 'clear_sync_log')) {
        $log_file = plugin_dir_path(__FILE__) . 'sync_error_log.txt';
        if (file_exists($log_file)) {
            file_put_contents($log_file, "Log cleared on " . date('Y-m-d H:i:s') . "\n");
            echo '<div class="updated"><p>✅ تم مسح سجل الأخطاء بنجاح!</p></div>';
        }
    }

    // إضافة حقوق الملكية في نهاية الصفحة
    add_action('admin_footer', function() {
        echo '<div style="text-align: center; margin-top: 30px; padding: 15px; background: #f8f9fa; border-top: 1px solid #e2e4e7;">
                <p style="margin: 0; color: #666;">
                    إضافة مزامنة المقالات | تم التطوير بواسطة <a href="https://www.linkedin.com/in/ammar-alasfer-b2933415a/" target="_blank">عمار الأصفر</a> | جميع الحقوق محفوظة &copy; ' . date('Y') . '
                </p>
            </div>';
    });
}

// تسجيل أكشنات الأجاكس وجلب البوستات
add_action('wp_ajax_sync_existing_posts_batch', 'handle_sync_existing_posts_batch');
add_action('wp_ajax_sync_single_post', 'handle_sync_single_post');
add_action('wp_ajax_test_target_connection', 'handle_test_target_connection');

function handle_sync_existing_posts_batch() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ];

    $posts = get_posts($args);

    wp_send_json_success([
        'posts' => $posts,
        'total' => count($posts),
    ]);
}

function handle_sync_single_post() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permission denied');
        return;
    }
    
    if (!isset($_GET['post_id'])) {
        wp_send_json_error('Missing post ID');
        return;
    }
    
    $post_id = intval($_GET['post_id']);
    $post = get_post($post_id);

    if ($post) {
        $result = sync_post_with_image_to_target($post_id, $post, true);

        if ($result) {
            wp_send_json_success();
        } else {
            wp_send_json_error();
        }
    } else {
        wp_send_json_error();
    }
}

// وظيفة اختبار الاتصال بالموقع الهدف
function handle_test_target_connection() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied']);
        return;
    }
    
    $target_site = get_option('sync_target_site', '');
    $target_user = get_option('sync_target_user', '');
    $target_password = get_option('sync_target_password', '');
    
    if (empty($target_site) || empty($target_user) || empty($target_password)) {
        wp_send_json_error(['message' => 'Please fill in all settings fields first']);
        return;
    }
    
    $result = test_target_site_auth($target_site, $target_user, $target_password);
    
    if ($result === true) {
        wp_send_json_success(['message' => 'Connection successful! User has required permissions.']);
    } else {
        wp_send_json_error(['message' => $result]);
    }
}

// دالة التحقق من وجود المقالة في الموقع الهدف
function check_post_exists_on_target($post_slug, $target_site, $target_user, $target_password) {
    $search_url = rtrim($target_site, '/') . '/wp-json/wp/v2/posts?slug=' . urlencode($post_slug);
    $auth = base64_encode($target_user . ':' . $target_password);

    $response = wp_remote_get($search_url, [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
        ],
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        sync_log_error('خطأ في التحقق من وجود المقالة: ' . $response->get_error_message());
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return !empty($body);
}

// دالة مزامنة البوست مع رفع الصورة
function sync_post_with_image_to_target($post_ID, $post, $update) {
    if ($post->post_status !== 'publish' || $post->post_type !== 'post') {
        return false;
    }

    $target_site = rtrim(get_option('sync_target_site'), '/');
    $target_user = get_option('sync_target_user');
    $target_password = get_option('sync_target_password');
    $target_category = get_option('sync_target_category');

    if (!$target_site || !$target_user || !$target_password || !$target_category) {
        sync_log_error('معرف المقالة ' . $post_ID . ' - خطأ: إعدادات التكوين غير مكتملة');
        return false;
    }

    // التحقق من وجود المقالة في الموقع الهدف
    if (check_post_exists_on_target($post->post_name, $target_site, $target_user, $target_password)) {
        sync_log_error('معرف المقالة ' . $post_ID . ' - تم تخطي المقالة: المقالة موجودة بالفعل في الموقع الهدف');
        return true;
    }

    // التحقق من الاتصال بالموقع الهدف قبل المزامنة
    $test_response = wp_remote_get($target_site . '/wp-json/wp/v2/types/post', [
        'headers' => [
            'Authorization' => 'Basic ' . base64_encode($target_user . ':' . $target_password),
        ],
        'timeout' => 30,
    ]);
    
    if (is_wp_error($test_response) || wp_remote_retrieve_response_code($test_response) !== 200) {
        sync_log_error('Post ID ' . $post_ID . ' - Error: Cannot connect to target site - ' . 
            (is_wp_error($test_response) ? $test_response->get_error_message() : 'HTTP Status: ' . wp_remote_retrieve_response_code($test_response)));
        return false;
    }

    $thumbnail_id = get_post_thumbnail_id($post_ID);
    $image_url = $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : null;
    $uploaded_image_id = null;

    if ($image_url) {
        $uploaded_image_id = upload_image_to_target($image_url, $target_site, $target_user, $target_password);
        if ($uploaded_image_id === null) {
            sync_log_error('Post ID ' . $post_ID . ' - Warning: Failed to upload featured image');
        }
    }

    $data = [
        'title'       => $post->post_title,
        'content'     => $post->post_content,
        'excerpt'     => $post->post_excerpt,
        'slug'        => $post->post_name,
        'status'      => 'publish',
        'categories'  => [intval($target_category)],
    ];

    if ($uploaded_image_id) {
        $data['featured_media'] = $uploaded_image_id;
    }

    $post_url = $target_site . '/wp-json/wp/v2/posts/';
    $auth = base64_encode($target_user . ':' . $target_password);

    // تسجيل بيانات الطلب للتشخيص (تأكد من عدم تسجيل كلمة المرور)
    sync_log_error('Post ID ' . $post_ID . ' - DEBUG: Attempting to create post with title: ' . $post->post_title);
    
    $response = wp_remote_post($post_url, [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($data),
        'timeout' => 60, // زيادة مهلة الاتصال
    ]);

    if (is_wp_error($response)) {
        sync_log_error('Post ID ' . $post_ID . ' - Error: ' . $response->get_error_message());
        return false;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 201) {
        sync_log_error('Post ID ' . $post_ID . ' - Error: HTTP Status ' . $status_code . ' - ' . wp_remote_retrieve_body($response));
        return false;
    }

    return true;
}

// اختبار اتصال API والصلاحيات
function test_target_site_auth($target_site, $target_user, $target_password) {
    if (empty($target_site) || empty($target_user) || empty($target_password)) {
        return "Missing configuration";
    }
    
    $target_site = rtrim($target_site, '/');
    $auth = base64_encode($target_user . ':' . $target_password);
    
    // اختبار #1: التحقق من الاتصال بالموقع
    $test_response = wp_remote_get($target_site . '/wp-json/', [
        'timeout' => 30,
    ]);
    
    if (is_wp_error($test_response)) {
        return "Cannot connect to WordPress site: " . $test_response->get_error_message();
    }
    
    if (wp_remote_retrieve_response_code($test_response) !== 200) {
        return "Site does not have REST API enabled or accessible. HTTP Status: " . wp_remote_retrieve_response_code($test_response);
    }
    
    // اختبار #2: التحقق من صحة اسم المستخدم وكلمة المرور
    $auth_response = wp_remote_get($target_site . '/wp-json/wp/v2/users/me', [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
        ],
        'timeout' => 30,
    ]);
    
    if (is_wp_error($auth_response)) {
        return "Authentication error: " . $auth_response->get_error_message();
    }
    
    if (wp_remote_retrieve_response_code($auth_response) === 401) {
        return "Invalid username or application password. Please check your credentials.";
    }
    
    // اختبار #3: التحقق من صلاحيات المستخدم لإنشاء المنشورات
    $capabilities_response = wp_remote_get($target_site . '/wp-json/wp/v2/users/me?context=edit', [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
        ],
        'timeout' => 30,
    ]);
    
    if (!is_wp_error($capabilities_response) && wp_remote_retrieve_response_code($capabilities_response) === 200) {
        $user_data = json_decode(wp_remote_retrieve_body($capabilities_response), true);
        
        if (isset($user_data['capabilities'])) {
            // التحقق من صلاحيات إنشاء المنشورات ورفع الوسائط
            $can_publish = isset($user_data['capabilities']['publish_posts']) && $user_data['capabilities']['publish_posts'] === true;
            $can_upload = isset($user_data['capabilities']['upload_files']) && $user_data['capabilities']['upload_files'] === true;
            
            if (!$can_publish) {
                return "User does not have permission to publish posts on the target site.";
            }
            
            if (!$can_upload) {
                return "User does not have permission to upload media on the target site.";
            }
        }
    }
    
    return true;
}

// دالة رفع الصورة
function upload_image_to_target($image_url, $target_site, $target_user, $target_password) {
    if (empty($image_url)) {
        return null;
    }

    // التحقق من أنه يمكن الوصول إلى الصورة
    $image_data = @file_get_contents($image_url);
    if ($image_data === false) {
        sync_log_error('Error fetching image: ' . $image_url);
        return null;
    }
    
    $filename = basename($image_url);
    $upload_url = $target_site . '/wp-json/wp/v2/media';
    $auth = base64_encode($target_user . ':' . $target_password);
    
    // تحديد نوع الصورة بشكل صحيح
    $file_parts = pathinfo($filename);
    $extension = strtolower($file_parts['extension'] ?? '');
    
    $content_type = 'image/jpeg'; // الافتراضي
    if ($extension === 'png') {
        $content_type = 'image/png';
    } elseif ($extension === 'gif') {
        $content_type = 'image/gif';
    } elseif ($extension === 'webp') {
        $content_type = 'image/webp';
    }

    $response = wp_remote_post($upload_url, [
        'headers' => [
            'Authorization' => 'Basic ' . $auth,
            'Content-Disposition' => 'attachment; filename="' . sanitize_file_name($filename) . '"',
            'Content-Type' => $content_type,
        ],
        'body' => $image_data,
        'timeout' => 60, // زيادة مهلة الاتصال
    ]);

    if (is_wp_error($response)) {
        sync_log_error('Error uploading image: ' . $response->get_error_message());
        return null;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 201) {
        sync_log_error('Error uploading image: HTTP Status ' . $status_code . ' - ' . wp_remote_retrieve_body($response));
        return null;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['id'] ?? null;
}

// إضافة هوك لمزامنة المنشورات الجديدة تلقائياً
add_action('publish_post', 'auto_sync_published_post', 10, 2);

function auto_sync_published_post($post_ID, $post) {
    if (wp_is_post_revision($post_ID) || wp_is_post_autosave($post_ID)) {
        return;
    }
    
    sync_post_with_image_to_target($post_ID, $post, false);
}

// إنشاء ملف السجل عند تنشيط الإضافة
register_activation_hook(__FILE__, 'sync_plugin_activation');

function sync_plugin_activation() {
    $log_file = plugin_dir_path(__FILE__) . 'sync_error_log.txt';
    if (!file_exists($log_file)) {
        file_put_contents($log_file, "Plugin activated on " . date('Y-m-d H:i:s') . "\n");
    }
}

// إضافة ملف CSS
function sync_posts_enqueue_styles() {
    wp_enqueue_style('sync-posts-styles', plugins_url('sync-posts-plugin.css', __FILE__));
}
add_action('admin_enqueue_scripts', 'sync_posts_enqueue_styles');