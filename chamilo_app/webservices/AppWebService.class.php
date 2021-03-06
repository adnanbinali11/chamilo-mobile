<?php
use ChamiloSession as Session;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ExtraFieldValues;
use Chamilo\CourseBundle\Entity\Repository\CAnnouncementRepository;
use Chamilo\CourseBundle\Entity\Repository\CNotebookRepository;
use Chamilo\CourseBundle\Entity\CLpCategory;
//use Chamilo\CoreBundle\Entity\Session;
use Chamilo\UserBundle\Entity\User;

require_once __DIR__ . '/../../../main/forum/forumconfig.inc.php';
require_once __DIR__ . '/../../../main/forum/forumfunction.inc.php';

class AppWebService extends WSAPP
{
    const SERVICE_NAME = 'AppREST';
    const EXTRA_FIELD_GCM_REGISTRATION = 'gcm_registration_id';

    /**
     * @var Session
     */
    private $session;
    /**
     * @var Course
     */
    private $course;

    /**
     * Rest constructor.
     * @param string $username
     * @param string $apiKey
     */
    public function __construct($username, $apiKey)
    {
        parent::__construct($username, $apiKey);
    }

    /**
     * Set the current course
     * @param int $id
     * @throws Exception
     */
    public function setCourse($id)
    {
        if (!$id) {
            $this->course = null;

            return;
        }

        $em = Database::getManager();
        /** @var Course $course */
        $course = $em->find('ChamiloCoreBundle:Course', $id);

        if (!$course) {
            throw new Exception(get_lang('NoCourse'));
        }

        $this->course = $course;
    }

    /** Set the current session
     * @param int $id
     * @throws Exception
     */
    public function setSession($id)
    {
        if (!$id) {
            $this->session = null;

            return;
        }

        $em = Database::getManager();
        /** @var Session $session */
        $session = $em->find('ChamiloCoreBundle:Session', $id);

        if (!$session) {
            throw new Exception(get_lang('NoSession'));
        }

        $this->session = $session;
    }

    /**
     * Generate the api key for a user
     * @param int $userId The user id
     * @return string The api key
     */
    public function generateApiKey($userId)
    {
        $apiKey = UserManager::get_api_keys($userId, self::SERVICE_NAME);

        if (empty($apiKey)) {
            UserManager::add_api_key($userId, self::SERVICE_NAME);

            $apiKey = UserManager::get_api_keys($userId, self::SERVICE_NAME);
        }

        return current($apiKey);
    }

    /**
     * Get the user api key
     * @param string $username The user name
     * @return string The api key
     */
    public function getApiKey($username)
    {
        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        if ($this->apiKey !== null) {
            return $this->apiKey;
        } else {
            $this->apiKey = $this->generateApiKey($userId);

            return $this->apiKey;
        }
    }

    /**
     * Get the user info and user api key
     * @param string $username The user name
     * @return array The api key join with user info 
     */
    public function getUserInfoApiKey($username)
    {
        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        if ($this->apiKey !== null) {
            $userInfo['apiKey'] = $this->apiKey;

            return $userInfo;
        } else {
            $this->apiKey = $this->generateApiKey($userId);
            $userInfo['apiKey'] = $this->apiKey;

            return $userInfo;
        }
    }

    /**
     * @param string $username
     * @param string $apiKeyToValidate
     * @return Rest
     * @throws Exception
     */
    public static function validate($username, $apiKeyToValidate)
    {
        $apiKey = self::findUserApiKey($username, self::SERVICE_NAME);

        if ($apiKey != $apiKeyToValidate) {
            throw new Exception(get_lang('InvalidApiKey'));
        }

        return new self($username, $apiKey);
    }

    /**
     * Create the gcm_registration_id extra field for users
     */
    public static function init()
    {
        $extraField = new ExtraField('user');
        $fieldInfo = $extraField->get_handler_field_info_by_field_variable(self::EXTRA_FIELD_GCM_REGISTRATION);

        if (empty($fieldInfo)) {
            $extraField->save([
                'variable' => self::EXTRA_FIELD_GCM_REGISTRATION,
                'field_type' => ExtraField::FIELD_TYPE_TEXT,
                'display_text' => self::EXTRA_FIELD_GCM_REGISTRATION,
            ]);
        }
    }

    /**
     * Check if the api is valid for a user
     * @param string $username The username
     * @param string $apiKeyToValidate The api key
     * @return boolean Whether the api belongs to the user return true. Otherwise return false
     */
    public static function isValidApiKey($username, $apiKeyToValidate)
    {
        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        $apiKeys = UserManager::get_api_keys($userId, self::SERVICE_NAME);
        if (!empty($apiKeys)) {
            $apiKey = current($apiKeys);

            if ($apiKey == $apiKeyToValidate) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $registrationId
     * @return bool
     */
    public function setGcmId($registrationId)
    {
        $registrationId = Security::remove_XSS($registrationId);
        $extraFieldValue = new ExtraFieldValue('user');

        return $extraFieldValue->save([
            'variable' => self::EXTRA_FIELD_GCM_REGISTRATION,
            'value' => $registrationId,
            'item_id' => $this->user->getId(),
        ]);
    }

    public function checkCondition($userId) {
        $platformUser = api_get_user_info($userId);
        $_user['user_id'] = $platformUser['user_id'];
        $result = true;

        $file = api_get_path(SYS_CODE_PATH).'auth/conditional_login/conditional_login.php';
        if (file_exists($file)) {
            include_once $file;
            if (isset($login_conditions)) {
                foreach ($login_conditions as $condition) {
                    //If condition fails we redirect to the URL defined by the condition
                    if (isset($condition['conditional_function'])) {
                        $function = $condition['conditional_function'];
                        $result = $function($_user);
                        if ($result == false) {
                            $result = false;
                        }
                    }
                }
            }
        }

        return $result;
    }

    public function getCondition($userId) {
        $platformUser = api_get_user_info($userId);
        $_user['user_id'] = $platformUser['user_id'];
        $result = '';

        $language = api_get_interface_language();
        $language = api_get_language_id($language);
        $term_preview = LegalManager::get_last_condition($language);

        if (!$term_preview) {
            //we load from the platform
            $language = api_get_setting('platformLanguage');
            $language = api_get_language_id($language);
            $term_preview = LegalManager::get_last_condition($language);

            //if is false we load from english
            if (!$term_preview) {
                $language = api_get_language_id('english');
                $term_preview = LegalManager::get_last_condition($language);
            }
        }

        return $term_preview;
    }

    public function setConditions($userId, $legalAcceptType) {
        // Update the terms & conditions.
        if (isset($legalAcceptType)) {
            $cond_array = explode(':', $legalAcceptType);
            if (!empty($cond_array[0]) && !empty($cond_array[1])) {
                $time = time();
                $condition_to_save = intval($cond_array[0]).':'.intval($cond_array[1]).':'.$time;
                UserManager::update_extra_field_value(
                    $userId,
                    'legal_accept',
                    $condition_to_save
                );

                $bossList = UserManager::getStudentBossList($userId);
                if (!empty($bossList)) {
                    $bossList = array_column($bossList, 'boss_id');
                    $currentUserInfo = api_get_user_info($userId);
                    foreach ($bossList as $bossId) {
                        $subjectEmail = sprintf(
                            get_lang('UserXSignedTheAgreement'),
                            $currentUserInfo['complete_name']
                        );
                        $contentEmail = sprintf(
                            get_lang('UserXSignedTheAgreementTheY'),
                            $currentUserInfo['complete_name'],
                            api_get_local_time($time)
                        );

                        MessageManager::send_message_simple(
                            $bossId,
                            $subjectEmail,
                            $contentEmail,
                            $userId
                        );
                    }
                }
            }
        }

        return true;
    }

    public function getCatalog($code)
    {
        $data = [];
        $data_course = [];
        $model = new Auth();

        $data_category = [];
        //$browse_course_categories = $model->browse_course_categories(); //1.11.6
        $browse_course_categories = CoursesAndSessionsCatalog::getCourseCategories(); //1.11.8
        foreach ($browse_course_categories[0] as $category) {
            $data_category[] = [
                'id' => $category['code'],
                'name' => $category['name'],
                'count_courses' => $category['count_courses'],
            ];
        }
        $data['categories_select'] = $data_category;
        $data['user_id'] = $this->user->getId();
        $courses = CoursesAndSessionsCatalog::getCoursesInCategory($code, null, null);
        $data['code'] = $code;

        foreach ($courses as $courseId) {
            /** @var Course $course */
            $course = Database::getManager()->find('ChamiloCoreBundle:Course', $courseId['real_id']);
            $teachers = CourseManager::getTeachersFromCourse($courseId['real_id']);

            $data_course[] = [
                'id' => $course->getId(),
                'title' => $course->getTitle(),
                'code' => $course->getCode(),
                'directory' => $course->getDirectory(),
                'urlPicture' => CourseManager::getPicturePath($course, true),
                'teachers' => $teachers,
                'category' => $courseId['category'],
                'registration_code' => $courseId['registration_code'],
                'subscribe' => $courseId['subscribe'],
                'visibility' => $courseId['visibility']
            ];
        }

        $data['courses_in_category'] = $data_course;

        // getting all the courses to which the user is subscribed to
        $curr_user_id = $this->user->getId();
        $user_courses = $model->get_courses_of_user($curr_user_id);
        $user_coursecodes = [];

        // we need only the course codes as these will be used to match against the courses of the category
        if ($user_courses != '') {
            foreach ($user_courses as $key => $value) {
                $user_coursecodes[] = $value['code'];
            }
        }

        $user = api_get_user_info($curr_user_id);
        if (isset($user['status']) && $user['status'] == DRH) {
            $courses = CourseManager::get_courses_followed_by_drh($curr_user_id);
            foreach ($courses as $course) {
                $user_coursecodes[] = $course['code'];
            }
        }

        $data['user_coursecodes'] = $user_coursecodes;
        //$sessions = $this->model->browseSessions($date, $limit); // Apartado de sesiones
        $data['sessions_in_category'] = [];
        $data['catalogShowCoursesSessions'] = api_get_setting('catalog_show_courses_sessions');

        return $data;
    }

    /**
     * Subscribe a student to a course
     * @param string $code The course code
     * @return int The course id or false if error
     */
    public function subscribeCourse($code, $password = '')
    {
        $user_id = $this->user->getId();
        $all_course_information = CourseManager::get_course_information($code);
        if ( $all_course_information['registration_code'] == '' || $password == $all_course_information['registration_code']) {
            if (api_is_platform_admin_by_id($user_id)) {
                $status_user_in_new_course = COURSEMANAGER;
            } else {
                $status_user_in_new_course = null;
            }

            if (CourseManager::subscribeUser($user_id, $code, $status_user_in_new_course)) {
                $send = api_get_course_setting('email_alert_to_teacher_on_new_user_in_course', $code);
                if ($send == 1) {
                    CourseManager::email_to_tutor(
                        $user_id,
                        $all_course_information['real_id'],
                        $send_to_tutor_also = false
                    );
                } else if ($send == 2) {
                    CourseManager::email_to_tutor(
                        $user_id,
                        $all_course_information['real_id'],
                        $send_to_tutor_also = true
                    );
                }
                $message = sprintf(get_lang('EnrollToCourseXSuccessful'), $all_course_information['title']);
                $id = $all_course_information['id'];
            } else {
                $message = get_lang('ErrorContactPlatformAdmin');
                $id = false;
            }

            return [
                'id' => $id,
                'message' => $message,
                'password' => false,
            ];
        } else if (!empty($password)) {
            $message = get_lang('CourseRegistrationCodeIncorrect');

            return [
                'id' => false,
                'message' => $message,
            ];
        } else {
            $message = get_lang('CourseRequiresPassword') . '<br />';
            $message .= $all_course_information['title'].' ('.$all_course_information['visual_code'].') ';

            return [
                'id' => false,
                'message' => $message,
                'password' => true,
            ];
        }
    }

    /**
     * Get the count of new messages for a user
     * @param string $username The username
     * @param int $lastId The id of the last received message
     * @return int The count fo new messages
     */
    public function countNewMessages($username, $lastId = 0)
    {
        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        return MessageManager::countMessagesFromLastReceivedMessage($userId, $lastId);
    }

    /**
     * Get the list of new messages for a user
     * @param string $username The username
     * @param int $lastId The id of the last received message
     * @return array the new message list
     */
    public function getNewMessages($username, $lastId = 0)
    {
        global $_configuration;
        $ruta = $_configuration['root_web'];

        $messages = [];
        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        //$lastMessages = MessageManager::getMessagesFromLastReceivedMessage($userId, $lastId);

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);

        $lastMessages = [];

        $sql = "SELECT m.*, u.user_id, u.lastname, u.firstname "
            . "FROM $messagesTable as m "
            . "INNER JOIN $userTable as u "
            . "ON m.user_sender_id = u.user_id "
            . "WHERE m.user_receiver_id = $userId "
            . "AND (m.msg_status = '0' OR m.msg_status = '1') "
            . "AND m.id > $lastId "
            . "ORDER BY m.send_date DESC";

        $result = Database::query($sql);

        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $lastMessages[] = $row;
            }
        }

        foreach ($lastMessages as $message) {
            $hasAttachments = MessageManager::hasAttachments($message['id']);

            $messages[] = [
                'id' => $message['id'],
                'title' => $message['title'],
                'sender' => [
                    'id' => $message['user_id'],
                    'lastname' => $message['lastname'],
                    'firstname' => $message['firstname'],
                    'completeName' => api_get_person_name($message['firstname'], $message['lastname']),
                ],
                'status' => $message['msg_status'],
                'sendDate' => $message['send_date'],
                'content' => str_replace('src="/', 'src="'.$ruta, $message['content']),
                'hasAttachments' => $hasAttachments,
                'platform' => [
                    'website' => api_get_path(WEB_PATH),
                    'messagingTool' => api_get_path(WEB_PATH) . 'main/messages/inbox.php',
                ]
            ];
        }

        return $messages;
    }

    public function getOutMessages($username, $lastId = 0)
    {
        global $_configuration;
        $ruta = $_configuration['root_web'];

        $messages = [];

        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        //$lastMessages = MessageManager::getMessagesFromLastReceivedMessage($userId, $lastId);

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);

        $lastMessages = [];

        $sql = "SELECT m.*, u.user_id, u.lastname, u.firstname "
            . "FROM $messagesTable as m "
            . "INNER JOIN $userTable as u "
            . "ON m.user_receiver_id = u.user_id "
            . "WHERE m.user_sender_id = $userId "
            . "AND m.msg_status = '4' "
            . "AND m.id > $lastId "
            . "ORDER BY m.send_date DESC";
        $result = Database::query($sql);

        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $lastMessages[] = $row;
            }
        }

        foreach ($lastMessages as $message) {
            $hasAttachments = MessageManager::hasAttachments($message['id']);

            $messages[] = [
                'id' => $message['id'],
                'title' => $message['title'],
                'sender' => [
                    'id' => $message['user_id'],
                    'lastname' => $message['lastname'],
                    'firstname' => $message['firstname'],
                    'completeName' => api_get_person_name($message['firstname'], $message['lastname']),
                ],
                'sendDate' => $message['send_date'],
                'content' => str_replace('src="/','src="'.$ruta,$message['content']),
                'hasAttachments' => $hasAttachments,
                'platform' => [
                    'website' => api_get_path(WEB_PATH),
                    'messagingTool' => api_get_path(WEB_PATH) . 'main/messages/inbox.php',
                ]
            ];
        }

        return $messages;
    }

    public function getRemoveMessages($list, $username)
    {
        $list = explode('-',$list);

        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        //$lastMessages = MessageManager::getMessagesFromLastReceivedMessage($userId, $lastId);

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);

        $listMessages = [];

        $sql = "SELECT m.id "
         . "FROM $messagesTable as m "
         . "WHERE m.user_receiver_id = $userId "
         . "AND (m.msg_status = '0' OR m.msg_status = '1') ";

        $result = Database::query($sql);

        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $listMessages[] = $row['id'];
            }
        }

        $list_remove = [];
        foreach($list as $value) {
            if (!in_array($value,$listMessages)) {
                $list_remove[] = $value;
            }
        }

        return $list_remove;
    }

    public function getRemoveOutMessages($list, $username)
    {
        $list = explode('-',$list);

        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        //$lastMessages = MessageManager::getMessagesFromLastReceivedMessage($userId, $lastId);

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);

        $listMessages = [];

        $sql = "SELECT m.id "
        . "FROM $messagesTable as m "
        . "WHERE m.user_sender_id = $userId "
        . "AND m.msg_status = '4'";

        $result = Database::query($sql);

        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $listMessages[] = $row['id'];
            }
        }

        $list_remove = [];
        foreach($list as $value) {
            if (!in_array($value,$listMessages)) {
                $list_remove[] = $value;
            }
        }

        return $list_remove;
    }

    /**
     * Get the list of new messages for a user
     * @param string $username The username
     * @param int $lastId The id of the last received message
     * @return array the new message list
     */
    public function getAllMessages($username)
    {
        global $_configuration;
        $ruta = $_configuration['root_web'];

        $messages = [];

        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];

        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);

        $all_messages = [];

        $sql = "SELECT m.*, u.user_id, u.lastname, u.firstname "
            . "FROM $messagesTable as m "
            . "INNER JOIN $userTable as u "
            . "ON m.user_sender_id = u.user_id "
            . "WHERE m.user_receiver_id = $userId "
            . "AND (m.msg_status = '0' OR m.msg_status = '1') "
            . "ORDER BY m.send_date DESC";

        $result = Database::query($sql);

        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $all_messages[] = $row;
            }
        }

        foreach ($all_messages as $message) {
            $hasAttachments = MessageManager::hasAttachments($message['id']);

            $messages[] = [
                'id' => $message['id'],
                'title' => $message['title'],
                'sender' => [
                    'id' => $message['user_id'],
                    'lastname' => $message['lastname'],
                    'firstname' => $message['firstname'],
                    'completeName' => api_get_person_name($message['firstname'], $message['lastname']),
                ],
                'status' => $message['msg_status'],
                'sendDate' => $message['send_date'],
                'content' => str_replace('src="/','src="'.$ruta,$message['content']),
                'hasAttachments' => $hasAttachments,
                'platform' => [
                    'website' => api_get_path(WEB_PATH),
                    'messagingTool' => api_get_path(WEB_PATH) . 'main/messages/inbox.php',
                ]
            ];
        }

        return $messages;
    }

    public function getAllOutMessages($username)
    {
        global $_configuration;
        $ruta = $_configuration['root_web'];

        $messages = [];
        $userInfo = api_get_user_info_from_username($username);
        $userId = $userInfo['user_id'];
        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $userTable = Database::get_main_table(TABLE_MAIN_USER);
        $all_messages = [];

        $sql = "SELECT m.*, u.user_id, u.lastname, u.firstname "
            . "FROM $messagesTable as m "
            . "INNER JOIN $userTable as u "
            . "ON m.user_receiver_id = u.user_id "
            . "WHERE m.user_sender_id = $userId "
            . "AND m.msg_status = '4' "
            . "ORDER BY m.send_date DESC";
    
        $result = Database::query($sql);

        if ($result !== false) {
            while ($row = Database::fetch_assoc($result)) {
                $all_messages[] = $row;
            }
        }

        foreach ($all_messages as $message) {
            $hasAttachments = MessageManager::hasAttachments($message['id']);

            $messages[] = [
                'id' => $message['id'],
                'title' => $message['title'],
                'sender' => [
                    'id' => $message['user_id'],
                    'lastname' => $message['lastname'],
                    'firstname' => $message['firstname'],
                    'completeName' => api_get_person_name($message['firstname'], $message['lastname']),
                ],
                'sendDate' => $message['send_date'],
                'content' => str_replace('src="/','src="'.$ruta,$message['content']),
                'hasAttachments' => $hasAttachments,
                'platform' => [
                    'website' => api_get_path(WEB_PATH),
                    'messagingTool' => api_get_path(WEB_PATH) . 'main/messages/inbox.php',
                ]
            ];
        }

        return $messages;
    }

    public function getNumMessages($userId) {
        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $sql = "SELECT COUNT(*) AS num FROM $messagesTable "
            . "WHERE user_receiver_id = $userId "
            . "AND msg_status = '1'";

        $result = Database::query($sql);
        if ($result !== false) {
            $row = Database::fetch_assoc($result);

            return $row['num'];
        } else {
            return 0;
        }
    }

    public function setReadMessage($messageId) {
        $messagesTable = Database::get_main_table(TABLE_MESSAGE);
        $sql = "UPDATE $messagesTable SET msg_status='0' WHERE id=$messageId";
        $result = Database::query($sql);
        if ($result !== false) {
            return true;
        } else {
            return false;
        }
    }

    public function getUsersMessage($user_id, $user_search)
    {
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        /* Fin login */
        Login::init_user($user_id, true);

        $track_online_table      = Database::get_main_table(TABLE_STATISTIC_TRACK_E_ONLINE);
        $tbl_my_user             = Database::get_main_table(TABLE_MAIN_USER);
        $tbl_my_user_friend      = Database::get_main_table(TABLE_MAIN_USER_REL_USER);
        $tbl_user                = Database::get_main_table(TABLE_MAIN_USER);
        $tbl_access_url_rel_user = Database :: get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
        $search                  = Database::escape_string($user_search);
        $access_url_id           = api_get_multiple_access_url() == 'true' ? api_get_current_access_url_id() : 1;
        $user_id                 = api_get_user_id();
        $is_western_name_order   = api_is_western_name_order();

        $likeCondition = " AND (firstname LIKE '%$search%' OR lastname LIKE '%$search%' OR email LIKE '%$search%') ";

        if (api_get_setting('allow_social_tool')=='true' && api_get_setting('allow_message_tool') == 'true') {
            // All users
            if (api_get_setting('allow_send_message_to_all_platform_users') == 'true' || api_is_platform_admin() ) {
                if ($access_url_id != 0) {
                    $sql = "SELECT DISTINCT u.user_id as id, u.firstname, u.lastname, u.email
                            FROM $tbl_user u LEFT JOIN $tbl_access_url_rel_user r ON u.user_id = r.user_id
                            WHERE
                                u.status <> 6  AND
                                u.user_id <> $user_id AND
                                r.access_url_id = $access_url_id
                                $likeCondition ";
                } else {
                    $sql = "SELECT DISTINCT u.user_id as id, u.firstname, u.lastname, u.email
                            FROM $tbl_user u
                            WHERE
                                u.status <> 6  AND
                                u.user_id <> $user_id
                                $likeCondition ";
                }
            } else {
                //only my contacts
                if ($access_url_id != 0) {
                    $sql = "SELECT DISTINCT u.user_id as id, u.firstname, u.lastname, u.email
                            FROM $tbl_access_url_rel_user r, $tbl_my_user_friend uf
                            INNER JOIN $tbl_my_user AS u
                            ON uf.friend_user_id = u.user_id
                            WHERE
                                u.status <> 6 AND
                                relation_type NOT IN(".USER_RELATION_TYPE_DELETED.", ".USER_RELATION_TYPE_RRHH.") AND
                                uf.user_id = $user_id AND
                                friend_user_id <> $user_id AND
                                u.user_id = r.user_id AND
                                r.access_url_id = $access_url_id
                                $likeCondition";
                } else {
                    $sql = "SELECT DISTINCT u.user_id as id, u.firstname, u.lastname, u.email
                            FROM $tbl_my_user_friend uf
                            INNER JOIN $tbl_my_user AS u
                            ON uf.friend_user_id = u.user_id
                             WHERE
                                u.status <> 6 AND
                                relation_type NOT IN(".USER_RELATION_TYPE_DELETED.", ".USER_RELATION_TYPE_RRHH.") AND
                                uf.user_id = $user_id AND
                                friend_user_id <> $user_id
                                $likeCondition";
                }
            }
        } elseif (api_get_setting('allow_social_tool')=='false' && api_get_setting('allow_message_tool')=='true') {
            if (api_get_setting('allow_send_message_to_all_platform_users') == 'true') {
                $sql = "SELECT DISTINCT u.user_id as id, u.firstname, u.lastname, u.email
                        FROM $tbl_user u LEFT JOIN $tbl_access_url_rel_user r ON u.user_id = r.user_id
                        WHERE
                            u.status <> 6  AND
                            u.user_id <> $user_id AND
                            r.access_url_id = $access_url_id
                            $likeCondition ";
            } else {
                $time_limit = api_get_setting('time_limit_whosonline');
                $online_time = time() - $time_limit*60;
                $limit_date     = api_get_utc_datetime($online_time);
                $sql = "SELECT SELECT DISTINCT u.user_id as id, u.firstname, u.lastname, u.email
                        FROM $tbl_my_user u INNER JOIN $track_online_table t
                        ON u.user_id=t.login_user_id
                        WHERE login_date >= '".$limit_date."' AND
                        $likeCondition";
            }
        }
        $sql .=' LIMIT 20';
        $result = Database::query($sql);

        $showEmail = api_get_setting('show_email_addresses');
        $return = [];
        if (Database::num_rows($result) > 0) {
            while ($row = Database::fetch_array($result, 'ASSOC')) {
                $name = api_get_person_name($row['firstname'], $row['lastname']);
                if ($showEmail == 'true') {
                    $name .= ' ('.$row['email'].')';
                }
                $return[] = [
                    'text' => $name,
                    'id' => $row['id']
                ];
            }
        }

        return $return;
    }

    public function sendNewEmail($to_userid, $title, $text, $user_id)
    {
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        /* Fin login */
        Login::init_user($user_id, true);

        if (is_array($to_userid) && count($to_userid)> 0) {
            foreach ($to_userid as $user) {
                $res = MessageManager::send_message(
                    $user,
                    $title,
                    $text
                );
                /*
                    $_FILES,
                    $file_comments,
                    $group_id,
                    $parent_id
                );
                */
            }

            return true;
        } else {
            return false;
        }
    }

    public function sendReplyEmail($message_id, $title, $text, $check_quote, $user_id)
    {
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        /* Fin login */
        Login::init_user($user_id, true);

        $message_info = MessageManager::get_message_by_id($message_id);

        $user = $message_info['user_sender_id'];
        $reply = '';
        if ($check_quote == '1') {
            $user_reply_info = api_get_user_info($message_info['user_sender_id']);
            $reply .= '<p><br/></p>'.sprintf(
                get_lang('XWroteY'),
                $user_reply_info['complete_name'],
                Security::filter_terms($message_info['content'])
            );
        }
        $text_message = $reply.' '.$text;
        $res = MessageManager::send_message($user, $title, $text_message);
        if ($res) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * Get the list of courses for a user
     * @param int $user_id The id of the user
     * @return array the courses list
     */
    public function getCoursesList($user_id)
    {
        $courses = CourseManager::get_courses_list_by_user_id($user_id);
        $data = [];

        foreach ($courses as $courseId) {
            /** @var Course $course */
            $course = Database::getManager()->find('ChamiloCoreBundle:Course', $courseId['real_id']);

            if ($course->getVisibility() == COURSE_VISIBILITY_CLOSED || $course->getVisibility()== COURSE_VISIBILITY_HIDDEN) {
                continue;
            }

            //$teachers = CourseManager::get_teacher_list_from_course_code_to_string($course->getCode());
            $teachers = CourseManager::getTeacherListFromCourseCodeToString($course->getCode());

            $data[] = [
                'id' => $course->getId(),
                'title' => $course->getTitle(),
                'code' => $course->getCode(),
                'directory' => $course->getDirectory(),
                'urlPicture' => CourseManager::getPicturePath($course, true),
                'teachers' => $teachers
            ];
        }
        
        return $data;
    }
    
    /**
     * Get the list of sessions for a user
     * @param int $user_id The id of the user
     * @return array the sessions list
     */
    public function getSessionsList($user_id)
    {
        $list_categories = [];
        $listSessions = UserManager::get_sessions_by_category($user_id,false);

        foreach ($listSessions as $cat_session) {
            $list_sessions = [];
            foreach($cat_session['sessions'] as $sessions) {
                $list_courses = [];
                foreach($sessions['courses'] as $course_session) {
                    $infoCourse = api_get_course_info_by_id($course_session['real_id']);
                    $teachers = SessionManager::getCoachesByCourseSessionToString(
                        $sessions['session_id'],
                        $course_session['real_id']
                    );
                    $info_course_session = [];
                    $info_course_session['visibility'] = $course_session['visibility'];
                    $info_course_session['real_id'] = $course_session['real_id'];
                    $info_course_session['position'] = $course_session['position'];
                    $info_course_session['status'] = $course_session['status'];
                    $info_course_session['id'] = $infoCourse['real_id'];
                    $info_course_session['title'] = $infoCourse['title'];
                    $info_course_session['code'] = $infoCourse['code'];
                    $info_course_session['directory'] = $infoCourse['directory'];
                    $info_course_session['pictureUrl'] = $infoCourse['course_image_large'];
                    $info_course_session['teachers'] = $teachers;

                    $list_courses[] = $info_course_session;
                }
                $list_sessions[] = [
                    'name' => $sessions['session_name'],
                    'session_id' => $sessions['session_id'], 
                    'accessStartDate' => ($sessions['access_start_date']) ? api_format_date(api_get_local_time($sessions['access_start_date']), DATE_TIME_FORMAT_SHORT) : NULL,
                    'accessEndDate' => ($sessions['access_end_date']) ? api_format_date(api_get_local_time($sessions['access_end_date']), DATE_TIME_FORMAT_SHORT) : NULL,
                    'courses' => $list_courses
                ];
            }
             $list_categories[] = [
                'id' => $cat_session['session_category']['id'],
                'name' => $cat_session['session_category']['name'],
                'sessions' => $list_sessions
            ];
        }

        return $list_categories;
    }

    /**
     * Get the profile info user
     * @param int $user_id The id of the user
     * @return array the user info
     */
    public function getProfile($user_id)
    {
        $user = api_get_user_info($user_id);
        $firstname = null;
        $lastname = null;

        if (isset($user['firstname']) && isset($user['lastname'])) {
            $firstname = $user['firstname'];
            $lastname = $user['lastname'];
        } elseif (isset($user['firstName']) && isset($user['lastName'])) {
            $firstname = isset($user['firstName']) ? $user['firstName'] : null;
            $lastname = isset($user['lastName']) ? $user['lastName'] : null;
        }

        $user['complete_name'] = api_get_person_name($firstname, $lastname);
        $user['complete_name_with_username'] = $result['complete_name'];

        if (!empty($user['username'])) {
            $user['complete_name_with_username'] = $user['complete_name'].' ('.$user['username'].')';
        }

        $t_uf  = Database :: get_main_table(TABLE_EXTRA_FIELD);
        $t_ufv = Database :: get_main_table(TABLE_EXTRA_FIELD_VALUES);

        $extra = [];

        $sql = "SELECT a.display_text ,b.value "
              ." FROM $t_uf a INNER JOIN $t_ufv b ON a.id=b.field_id "
              ." WHERE a.visible_to_self='1' AND b.item_id='".$user_id."' AND b.value<>'';";
        $rs = Database::query($sql);
        while( $row = Database::fetch_row($rs) ) {
            $extra[] = $row;
        }
        $user['extra'] = $extra;
        $user['picture_uri'] = UserManager::getUserPicture($user_id, USER_IMAGE_SIZE_BIG);

        return $user;
    }

    /**
     * Register course access
     * @param int $c_id The id course
     * @param int $user_id The id user
     * @return info course (title and visibility icons)
     */
    public function registerAccessCourse($courseId, $userId, $s_id = 0)
    {
        /* LOGIN */
        $courseInfo = api_get_course_info_by_id($courseId);
        $courseCode = $courseInfo['code'];
        $platformUser = api_get_user_info($userId);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($userId, true);
        Login::init_course($courseInfo['code'], true);
        if ($sessionId > 0) {
            Session::write('id_session', $sessionId);
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        /* Fin login */
        Event::accessCourse();
        // Return title course and visible icon array
        $results = [];
        $results['title'] = $courseInfo['title'];
        $t_tool = Database :: get_course_table(TABLE_TOOL_LIST);
        $sql = "SELECT * FROM $t_tool
                WHERE
                c_id = $courseId AND
                    (category = 'authoring' OR category = 'interaction')
                ";
        $result = Database::query($sql);
        $section = $visibility = $icons = [];
        $web_root = api_get_path(WEB_PATH);
        while ($row = Database::fetch_assoc($result)) {
            $visibility[$row['link']]= $row['visibility'];
            if (isset($row['custom_icon']) && $row['custom_icon'] != "") {
                $icons[$row['link']] = $web_root.'courses/'.$courseCode.'/upload/course_home_icons/'.$row['custom_icon'];
            } else {
                $icons[$row['link']] = '';
            }
            //$icons[$row['link']]= $row['custom_icon'];
        }

        $course_tool_table = Database::get_course_table(TABLE_TOOL_LIST);
        $session_id = $s_id;
        $condition_session = api_get_session_condition($session_id, true, true, 't.session_id');
        $sql = "SELECT * FROM $course_tool_table t
                WHERE category = 'plugin' AND c_id = $courseId $condition_session
                ORDER BY id";
        $result = Database::query($sql);
        while ($row = Database::fetch_assoc($result)) {
            $visibility[$row['name']] = $row['visibility'];
            $icons[$row['name']] = $web_root.'main/img/icons/64/'.$row['image'];
        }
        $results['section']['visibility'] = $visibility;
        $results['section']['icons'] = $icons;
        $is_allowed_to_edit = api_is_allowed_to_edit(null, true);
        if ($session_id > 0) {
            $results['statusUser'] = api_is_coach($s_id, $courseId);
        } else {
            $results['statusUser'] = api_get_status_of_user_in_course($userId, $courseId);
        }

        return $results;
    }

    /**
     * Get description of course
     * @param int $c_id The id course
     * @param string $username
     * @param int $s_id The id session
     * @return array the all descriptions
     */
    public function getDescription($c_id, $username, $s_id = 0)
    {
        $courseInfo = api_get_course_info_by_id($c_id);
        $user_id = UserManager::get_user_id_from_username($username);

        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        // Tracking
        eventAccessTool(TOOL_COURSE_DESCRIPTION);

        global $_configuration;
        $ruta = $_configuration['root_web'];

        $t_course_desc = Database::get_course_table(TABLE_COURSE_DESCRIPTION);
        $sql = "SELECT * FROM $t_course_desc
                WHERE c_id = $c_id AND session_id = $s_id";
        $sql_result = Database::query($sql);
        $results = [];
        while ($row = Database::fetch_assoc($sql_result)) {
            $results[] = [
                'title' => $row['title'],
                'content' => str_replace('src="/','src="'.$ruta,$row['content']),
            ];
        }

        return $results;
    }

    /**
     * Get learnpath of course
     * @param int $c_id The id course
     * @param int $user_id
     * @param int $s_id The id session
     * @return array the all learnpath
     */
    public function getLearnpaths($c_id, $user_id, $s_id = 0)
    {
        //Login
        $courseInfo = api_get_course_info_by_id($c_id);
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        // Tracking
        eventAccessTool(TOOL_LEARNPATH);

        global $_configuration;
        $ruta = $_configuration['root_web'];
        $token = Security::get_token();
        $categoriesTempList = learnpath::getCategories(api_get_course_int_id());
        $categoryTest = new \Chamilo\CourseBundle\Entity\CLpCategory();
        $categoryTest->setId(0);
        $categoryTest->setName(get_lang('WithOutCategory'));
        $categoryTest->setPosition(0);

        $categories = [
            $categoryTest
        ];

        if (!empty($categoriesTempList)) {
            $categories = array_merge($categories, $categoriesTempList);
        }

        $userId = api_get_user_id();
        $userInfo = api_get_user_info();
        $lpIsShown = false;

        $test_mode = api_get_setting('server_type');

        $data = [];

        foreach ($categories as $item) {
            $categoryId = $item->getId();
            $categoryName = '';
            if ($categoryId != 0) {
                $categoryName = $item->getName();
            }

            $list = new LearnpathList(
                api_get_user_id(),
                null,
                null,
                null,
                false,
                $categoryId
            );

            $flat_list = $list->get_flat_list();
            // Hiding categories with out LPs (only for student)
            if (empty($flat_list) && !api_is_allowed_to_edit()) {
                continue;
            }

            $listData = [];

            if (!empty($flat_list)) {
                foreach ($flat_list as $id => $details) {
                    if (!$is_allowed_to_edit && $details['lp_visibility'] == 0) {
                        // This is a student and this path is invisible, skip.
                        continue;
                    }

                    // Check if the learnpath is visible for student.
                    if (!$is_allowed_to_edit && !learnpath::is_lp_visible_for_student(
                            $id,
                            $userId
                        )
                    ) {
                        continue;
                    }

                    $start_time = $end_time = '';
                    $time_limits = false;

                    //This is an old LP (from a migration 1.8.7) so we do nothing
                    if ((empty($details['created_on']) || $details['created_on'] == '0000-00-00 00:00:00') &&
                        (empty($details['modified_on']) || $details['modified_on'] == '0000-00-00 00:00:00')
                    ) {
                        $time_limits = false;
                    }

                    //Checking if expired_on is ON
                    if ($details['expired_on'] != '' && $details['expired_on'] != '0000-00-00 00:00:00') {
                        $time_limits = true;
                    }

                    if ($time_limits) {
                        // Check if start time
                        if (!empty($details['publicated_on']) && $details['publicated_on'] != '0000-00-00 00:00:00' &&
                            !empty($details['expired_on']) && $details['expired_on'] != '0000-00-00 00:00:00'
                        ) {
                            $start_time = api_strtotime(
                                $details['publicated_on'],
                                'UTC'
                            );
                            $end_time = api_strtotime(
                                $details['expired_on'],
                                'UTC'
                            );
                            $now = time();
                            $is_actived_time = false;

                            if ($now > $start_time && $end_time > $now) {
                                $is_actived_time = true;
                            }

                            if (!$is_actived_time) {
                                continue;
                            }
                        }
                    }
                    $start_time = $end_time = '';
                    $url_start_lp = api_get_cidreq().'&action=view&lp_id='.$id.'&isStudentView=true';
                    $name = Security::remove_XSS($details['lp_name']);
                    $my_title = $name;
                    $icon_learnpath = Display::return_icon(
                        'learnpath.png',
                        get_lang('LPName'),
                        '',
                        ICON_SIZE_SMALL
                    );

                    if ($details['lp_visibility'] == 0) {
                        $my_title = Display::tag(
                            'font',
                            $name,
                            ['class' => 'invisible']
                        );
                        $icon_learnpath = Display::return_icon(
                            'learnpath_na.png',
                            get_lang('LPName'),
                            '',
                            ICON_SIZE_SMALL
                        );
                    }

                    $progress = 0;
                    if (!api_is_invitee()) {
                        $progress = learnpath::getProgress(
                            $id,
                            $userId,
                            api_get_course_int_id(),
                            api_get_session_id()
                        );
                    }

                    $listData[] = [
                        'learnpath_icon' => $icon_learnpath,
                        'url_start' => rawurlencode($url_start_lp),
                        'title' => $my_title,
                        'start_time' => $start_time,
                        'end_time' => $end_time,
                        'dsp_progress' => ($progress == NULL || $progress == '' ) ? (0) : ($progress) ,
                    ];
                } // end foreach ($flat_list)
            }

            $data[] = [
                'category' => $categoryName,
                'lp_list' => $listData
            ];
        }

        return $data;
    }

    /**
     * Get link of course
     * @param int $c_id The id course
     * @param string $username
     * @return array the all notebook
     */
    public function getLink($c_id, $username, $s_id)
    {
        $courseInfo = api_get_course_info_by_id($c_id);
        $user_id = UserManager::get_user_id_from_username($username);

        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        // Tracking
        eventAccessTool(TOOL_NOTEBOOK);

        global $_configuration;
        $ruta = $_configuration['root_web'];

        $t_link = Database :: get_course_table(TABLE_LINK);
        $t_link_category = Database :: get_course_table(TABLE_LINK_CATEGORY);
        $sql = "SELECT * FROM $t_link_category
                WHERE
                c_id = $c_id AND
                session_id = $s_id 
                ORDER BY display_order ASC
                ";
        $result = Database::query($sql);
        $category = $links = [];

        //Categoria 0 - General
        $category[0] = [
            'category_title' => 'General',
            'description' => ''
        ];
        
        $sql = "SELECT * FROM $t_link
                WHERE
                c_id = $c_id AND
                session_id = $s_id AND
                category_id = 0
                ORDER BY display_order ASC";
        $result_link = Database::query($sql);
        $tmp = [];
        while ($aux = Database::fetch_array($result_link)) {
            $url = api_get_path(WEB_CODE_PATH).'link/link_goto.php?'.api_get_cidreq().'&link_id='.$aux['id'].'&link_url='.urlencode($aux['url']);
            $tmp[] = [
                'url' => $url,
                'title' => $aux['title'],
                'description' => $aux['description']
            ];
        }
        $links[0] = $tmp;

        // Resto de categorias
        while ($row = Database::fetch_array($result)) {
            $category[$row['id']] = [
                'category_title' => $row['category_title'],
                'description' => $row['description']
            ];
            $category_id = $row['id'];
            $sql = "SELECT * FROM $t_link 
                    WHERE
                    c_id = $c_id AND 
                    session_id = $s_id AND 
                    category_id = $category_id 
                    ORDER BY display_order ASC";
            $result_link = Database::query($sql);
            $tmp = [];
            while ($aux = Database::fetch_array($result_link)) {
                $url = api_get_path(WEB_CODE_PATH).'link/link_goto.php?'.api_get_cidreq().'&link_id='.$aux['id'].'&link_url='.urlencode($aux['url']);
                $tmp[] = [
                    'url' => $url,
                    'title' => $aux['title'],
                    'description' => $aux['description']
                ];
            }
            $links[$row['id']] = $tmp;
        }
        $results = ['category' => $category, 'links' => $links];

        return $results;
    }

    /**
     * Get notebook of course
     * @param int $c_id The id course
     * @param string $username 
     * @return array the all notebook
     */
    public function getNotebook($c_id, $username, $s_id)
    {
        $courseInfo = api_get_course_info_by_id($c_id);
        $user_id = UserManager::get_user_id_from_username($username);

        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        // Tracking
        eventAccessTool(TOOL_NOTEBOOK);

        global $_configuration;
        $ruta = $_configuration['root_web'];
        $t_notebook = Database :: get_course_table(TABLE_NOTEBOOK);
        $sql = "SELECT * FROM $t_notebook
                WHERE
                    c_id = $c_id AND
                    user_id = '" . api_get_user_id() . "' AND
                    session_id = $s_id
                ";
        $result = Database::query($sql);
        $results = [];
        while ($row = Database::fetch_array($result)) {
            $creation_date = api_get_local_time($row['creation_date'], null, date_default_timezone_get());
            $update_date = api_get_local_time($row['update_date'], null, date_default_timezone_get());
            if ($row['update_date']==$row['creation_date']) {
                $update = '';
            } else {
                $update = date_to_str_ago($update_date).' '.$update_date;
            }
            $results[] = [
                'id' => $row['notebook_id'],
                'title' => $row['title'],
                'description' => str_replace('src="/','src="'.$ruta,$row['description']),
                'creation_date' => date_to_str_ago($creation_date).' '.$creation_date,
                'update_date' => $update,
            ];
        }

        return $results;
    }

    public function createNotebook($c_id, $title, $text, $user_id, $s_id) {
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $courseInfo = api_get_course_info_by_id($c_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Event::eventLogin($_user['user_id']);
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        /* Fin login */

        $values = ['note_title'=>$title,'note_comment'=>$text];

        return NotebookManager::save_note($values);
    }

    /**
     * Get documents of course
     * @param int $c_id The id course
     * @return array the all documents
     */
    public function getDocuments($c_id, $path, $username, $s_id)
    {
        $user_id = UserManager::get_user_id_from_username($username);
        /* LOGIN */
        $courseInfo = api_get_course_info_by_id($c_id);
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Event::eventLogin($_user['user_id']);
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }
        /* Fin login */
        Event::event_access_tool(TOOL_DOCUMENT);

        $lib_path = api_get_path(LIBRARY_PATH);
        require_once $lib_path.'fileDisplay.lib.php';
        global $_configuration;
        $ruta = $_configuration['root_web'];
        $_course = api_get_course_info_by_id($c_id);
        $libpath = api_get_path(LIBRARY_PATH);
        require_once $libpath.'document.lib.php';

        $documents = DocumentManager::getAllDocumentData($_course, $path);

        $results = [];
        foreach($documents as $document) {
            if ($document['visibility'] == "1") {
                if ($document['filetype'] == "file") {
                    $icon = choose_image($document['path']);
                } else {
                    if ($document['path'] == '/shared_folder') {
                        $icon = 'folder_users.gif';
                    } elseif (strstr($document['path'], 'shared_folder_session_')) {
                        $icon = 'folder_users.gif';
                    } else {
                        $icon = 'folder_document.gif';
                        if ($document['path'] == '/audio') {
                            $icon = 'folder_audio.gif';
                        } elseif ($document['path'] == '/flash') {
                            $icon = 'folder_flash.gif';
                        } elseif ($document['path'] == '/images') {
                            $icon = 'folder_images.gif';
                        } elseif ($document['path'] == '/video') {
                            $icon = 'folder_video.gif';
                        } elseif ($document['path'] == '/images/gallery') {
                            $icon = 'folder_gallery.gif';
                        } elseif ($document['path'] == '/chat_files') {
                            $icon = 'folder_chat.gif';
                        } elseif ($document['path'] == '/learning_path') {
                            $icon = 'folder_learningpath.gif';
                        }
                    }
                }
                $results[] =[
                    'id' => $document['id'],
                    'filetype' => $document['filetype'],
                    'path' => $document['path'],
                    'filename' => basename($document['path']),
                    'title' => $document['title'],
                    'icon' => $icon,
                    'size' => $document['size'],
                ];
            }
        }

        return $results;
    }

    /**
     * Get announcements of course
     * @param int $c_id The course id
     * @param int $user_id 
     * @return array the all announcements
     */
    public function getAnnouncements($c_id, $user_id, $s_id = 0)
    {
        $session_id = $s_id;
        /* LOGIN */
        $courseInfo = api_get_course_info_by_id($c_id);
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }

        global $_configuration;
        $ruta = $_configuration['root_web'];

        $info_course = api_get_course_info_by_id($c_id);
        $info_user = api_get_user_info($user_id);
        $teacher_list = CourseManager::get_teacher_list_from_course_code($info_course['code']);

        $teacher_name = '';
        if (!empty($teacher_list)) {
            foreach ($teacher_list as $teacher_data) {
                $teacher_name = api_get_person_name($teacher_data['firstname'], $teacher_data['lastname']);
                $teacher_email = $teacher_data['email'];
                break;
            }
        }

        $courseLink = api_get_course_url($info_course['code'], $session_id);

        $tbl_announcement = Database::get_course_table(TABLE_ANNOUNCEMENT);
        $tbl_item_property = Database::get_course_table(TABLE_ITEM_PROPERTY);

        if (!empty($user_id) && is_numeric($user_id)) {
            $user_id = intval($user_id);
            $sql = "SELECT announcement.session_id, announcement.iid, announcement.c_id, announcement.id, announcement.title, announcement.content, announcement.display_order, toolitemproperties.insert_user_id,toolitemproperties.lastedit_date ".
                    "FROM $tbl_announcement announcement, $tbl_item_property toolitemproperties ".
                    "WHERE ".
                        "announcement.c_id = $c_id AND ".
                        "toolitemproperties.c_id = $c_id AND ".
                        "announcement.id = toolitemproperties.ref AND ".
                        "toolitemproperties.tool='announcement' AND ".
                        "( ".
                          "toolitemproperties.to_user_id='$user_id' AND ".
                          "(toolitemproperties.to_group_id='0' OR toolitemproperties.to_group_id is null) ".
                        ") ".
                        "AND toolitemproperties.visibility='1' ".
                        "AND (announcement.session_id = $s_id OR announcement.session_id='0' ) ".
                    "ORDER BY display_order DESC";

            $rs = Database::query($sql);
            $num_rows = Database::num_rows($rs);
            $content = '';
            $result = [];
            if ($num_rows > 0) {
                while ($myrow = Database::fetch_array($rs)) {
                    $info_user_publisher = api_get_user_info($myrow['insert_user_id']);
                    $content = $myrow['content'];
                    $content = str_replace('src="/','src="'.$ruta,$content);
                    $content = str_replace('((user_name))',$info_user['username'],$content);
                    $content = str_replace('((user_firstname))',$info_user['firstname'],$content);
                    $content = str_replace('((user_lastname))',$info_user['lastname'],$content);
                    $content = str_replace('((teacher_name))',$teacher_name,$content);
                    $content = str_replace('((teacher_email))',$teacher_email,$content);
                    $content = str_replace('((course_title))',$info_course['title'],$content);
                    $content = str_replace('((course_link))',Display::url($courseLink, $courseLink),$content);
                    $content = str_replace('((official_code))',$info_user['official_code'],$content);

                    $result[] = [
                       'iid' => $myrow['iid'],
                       'c_id' => $myrow['c_id'],
                       's_id' => $myrow['session_id'],
                       'a_id' => $myrow['id'],
                       'title' => $myrow['title'],
                       'content' => $content,
                       'teacher' => $info_user_publisher['firstname'].' '.$info_user_publisher['lastname'], 
                       'display_order' => $myrow['display_order'],
                       'last_edit' => api_get_local_time($myrow['lastedit_date']),
                    ];
                }

                return $result;
            } else {
                return $result;
            }
        } else {
            return false;
        }
    }

    /**
     * Get events of course
     * @param int $course_id The course id
     * @param int $user_id
     * @param int $session_id 
     * @return array the all events
     */
    public function getCourseEvents($c_id, $user_id, $s_id = 0)
    {
        $session_id = $s_id;
        $course_id = $c_id;
        /* LOGIN */
        $courseInfo = api_get_course_info_by_id($c_id);
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }

        global $_configuration;
        $ruta = $_configuration['root_web'];

        if (empty($course_id)) {
            return [];
        }

        $courseInfo = api_get_course_info_by_id($course_id);
        $course_id = $courseInfo['real_id'];
        $user_id = intval($user_id);
        $session_id = intval($session_id);
        $currentCourseId = api_get_course_int_id();

        $agenda = new Agenda();
        $type = 'course';
        $agenda->setType($type);
        $events = $agenda->getEvents(
            null,
            null,
            $currentCourseId,
            api_get_group_id(),
            null,
            'array'
        );

        usort($events, function ($a, $b) {
            $t1 = strtotime($a['start']);
            $t2 = strtotime($b['start']);
            return $t1 > $t2;
        });

        $results = [];
        foreach ($events as $row) {
            $description = $row['description'];
            $description = str_replace('src="/','src="'.$ruta,$description);
            $description = str_replace('src="../../','src="'.$ruta,$description);

            if ($row['allDay'] == 1 ) {
                $start_date = date("d/m/Y",strtotime($row['start_date_localtime']));
                $end_date = date("d/m/Y",strtotime($row['end_date_localtime']));
            } else {
                $start_date = date("d/m/Y H:i:s",strtotime($row['start_date_localtime']));
                $end_date = date("d/m/Y H:i:s",strtotime($row['end_date_localtime']));
            }

            $results[] = [
                'iid' => $row['unique_id'],
                'c_id' => $row['course_id'],
                'a_id' => $row['id'],
                'title' => $row['title'],
                'content' => $description, 
                'start_date' => $start_date, 
                'end_date' => $end_date,
                'all_day' => $row['allDay'],
            ];
        }

        return $results;
    }

    public function getForums($c_id, $user_id, $s_id = 0)
    {
        $session_id = $s_id;
        $course_id = $c_id;
        /* LOGIN */
        $courseInfo = api_get_course_info_by_id($c_id);
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        Login::init_user($user_id, true);
        Login::init_course($courseInfo['code'], true);
        if ($s_id > 0) {
            $_SESSION['id_session'] = $s_id;
        } else {
            Session::erase('session_name');
            Session::erase('id_session');
        }

        $course_code = $courseInfo['code'];

        /* TRACKING */
        eventAccessTool(TOOL_FORUM);

        $forum_list = get_forums();
        if (is_array($forum_list)) {
            foreach ($forum_list as $key => $value) {
                $last_post_info_of_forum = get_last_post_information($key, $course_id);
                $forum_list[$key]['last_poster'] = $last_post_info_of_forum['last_poster_firstname'].' '.$last_post_info_of_forum['last_poster_lastname'];
                $forum_list[$key]['last_post_date'] = api_convert_and_format_date($last_post_info_of_forum['last_post_date']);
            }
        } else {
            $forum_list = [];
        }

        $table_categories = Database :: get_course_table(TABLE_FORUM_CATEGORY);
        $table_item_property = Database :: get_course_table(TABLE_ITEM_PROPERTY);
        $sql = "SELECT *
                FROM ".$table_categories." forum_categories, ".$table_item_property." item_properties
                WHERE
                    forum_categories.cat_id=item_properties.ref AND
                    item_properties.visibility=1 AND
                    item_properties.tool = '".TOOL_FORUM_CATEGORY."' AND
                    forum_categories.c_id = '".$course_id."' AND item_properties.c_id = '".$course_id."'  
                ORDER BY forum_categories.cat_order ASC";

        $result = Database::query($sql);
        $forum_categories_list = [];

        while ($row = Database::fetch_array($result)) {
            $forum_categories_list[$row['cat_id']] = $row;
        }

        return [
            'info_forum' => $forum_list,
            'info_category' => $forum_categories_list,
        ];
    }

    public function getThreads($c_id, $forum_id, $user_id, $s_id = 0) {
        $courseInfo = api_get_course_info_by_id($c_id);
        $course_code = $courseInfo['code'];
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        /* Fin login */
        Login::init_user($user_id, true);
        Login::init_course($course_code, true);

        $thread_list = get_threads($forum_id, $c_id);
        getNotificationsPerUser($user_id = 0, true);

        foreach ($thread_list as $key => $value) {
            $postInfo = get_post_information($value['thread_last_post']);
            if (!empty($postInfo)) {
                $lastPoster = api_get_user_info($postInfo['poster_id']);
                $thread_list[$key]['last_post_name'] = $lastPoster['complete_name'];
            } else {
                $thread_list[$key]['last_post_name'] = get_lang('Anonymous');
            }

            if (!empty($value['insert_user_id'])) {
                $poster = api_get_user_info($value['insert_user_id']);
                $thread_list[$key]['thread_poster_name'] = $poster['complete_name'];
            } else {
                $thread_list[$key]['thread_poster_name'] = get_lang('Anonymous');
            }

            $thread_list[$key]['last_post_date'] = api_convert_and_format_date($value['thread_date']);
            $thread_list[$key]['insert_date'] = api_convert_and_format_date($value['insert_date']);

            $iconnotify = "notification_mail_na.png";
            if (is_array(
                isset($_SESSION['forum_notification']['thread']) ? $_SESSION['forum_notification']['thread'] : null
                )
            ) {
                if (in_array($value['thread_id'], $_SESSION['forum_notification']['thread'])) {
                    $iconnotify = "notification_mail.png";
                }
            }
            $thread_list[$key]['iconnotify'] = $iconnotify;

            //$origin = api_get_origin();
            $origin = "#post/".$c_id."/".$s_id."/".$forum_id."/".$value['thread_id'];
            $name = api_get_person_name($value['firstname'], $value['lastname']);
            $image = display_user_image($value['user_id'], $name, $origin);
            $image = str_replace ("#", $origin, $image);
            $thread_list[$key]['image'] = $image;
        }

        $table_forums = Database :: get_course_table(TABLE_FORUM);
        $sql = "SELECT forum_title
                FROM $table_forums forum
                WHERE forum.c_id = $c_id AND forum.forum_id = $forum_id";
        $rs = Database::query($sql);
        $row = Database::fetch_array($rs, 'ASSOC');

        $result_return = [
            'threads' => $thread_list,
            'forum_title' => $row['forum_title'],
        ];

        return $result_return;
    }

    public function getPosts($c_id, $forum_id, $thread_id) {
        global $_configuration;
        $ruta = $_configuration['root_web'];

        $courseInfo = api_get_course_info_by_id($c_id);
        $course_code = $courseInfo['code'];
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        /* Fin login */
        Login::init_user($user_id, true);
        Login::init_course($course_code, true);
        $forumInfo = get_forums($forum_id);
        $forum_title = $forumInfo['forum_title'];
        $forum_description = $forumInfo['forum_comment'];
        $post_list = getPosts($forumInfo, $thread_id);

        foreach ($post_list as $key => $value) {
            $post_list[$key]['date'] = api_convert_and_format_date($value['post_date']);
            $post_list[$key]['post_text'] = str_replace('src="/','src="'.$ruta,$value['post_text']);
            $post_list[$key]['post_text'] = str_replace('src="../../','src="'.$ruta,$post_list[$key]['post_text']);

            //Get attachment post
            $post_id = $value['post_id'];
            $attachment = getAllAttachment($post_id);
            $aux_path = [];
            $aux_filename = [];
            foreach ($attachment as $value2) {
                $aux_path[] = $value2['path'];
                $aux_filename[] = $value2['filename'];
            }
            $post_list[$key]['path'] = $aux_path;
            $post_list[$key]['filename'] = $aux_filename;

            $origin = api_get_origin();
            $posterId = isset($value['user_id']) ? $value['user_id'] : 0;
            $name = '';
            if (empty($posterId)) {
                $name = prepare4display($value['poster_name']);
            } else {
                if (isset($value['complete_name'])) {
                    $name = $value['complete_name'];
                }
            }
            $image = display_user_image($posterId, $name, $origin);
            $post_list[$key]['image'] = $image;

            if (!empty($value['poster_id'])) {
                $poster = api_get_user_info($value['poster_id']);
                if (!empty($poster)) {
                    $post_list[$key]['poster_name'] = $poster['complete_name'];
                } else {
                    $post_list[$key]['poster_name'] = get_lang('Anonymous');
                }
            } else {
                $post_list[$key]['poster_name'] = get_lang('Anonymous');
            }
        }
        $table_threads = Database :: get_course_table(TABLE_FORUM_THREAD);
        $sql = "SELECT thread_title FROM $table_threads thread
                WHERE thread.c_id = $c_id AND thread.thread_id = $thread_id";
        $rs = Database::query($sql);
        $row = Database::fetch_array($rs, 'ASSOC');
       
        $result_return = [
            'posts' => $post_list,
            'thread_title' => $row['thread_title'],
            'forum_title' => $forum_title,
            'forum_description' => $forum_description,
        ];
        increase_thread_view($thread_id);

        return $result_return;
    }

    public function createThread($c_id, $forum_id, $title, $text, $notice, $user_id, $s_id) {
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        //Event::eventLogin($_user['user_id']);
        /* Fin login */
        Login::init_user($user_id, true);
        //Login::init_course($c_id, true);

        $table_threads = Database :: get_course_table(TABLE_FORUM_THREAD);
        $table_posts = Database :: get_course_table(TABLE_FORUM_POST);

        $course_id = $c_id;
        $courseInfo = api_get_course_info_by_id($c_id);
        $user = api_get_user_info($user_id);

        $poster_name = $user['firstname'].' '.$user['lastname'];
        $post_date = api_get_utc_datetime();
        $visible = 1;
        $clean_post_title = Database::escape_string(stripslashes($title));
        $my_post_notification = isset($notice) ? $notice : null;

        $sql = "INSERT INTO $table_threads (c_id, thread_title, forum_id, thread_poster_id, thread_poster_name, thread_date, session_id)
                VALUES (
                    ".$course_id.",
                    '".$clean_post_title."',
                    '".Database::escape_string($forum_id)."',
                    '".Database::escape_string($user_id)."',
                    '".Database::escape_string(stripslashes(isset($poster_name) ? $poster_name : null))."',
                    '".Database::escape_string($post_date)."',
                    '".$s_id."')";
        Database::query($sql);
        $last_thread_id = Database::insert_id();
        $sql = "UPDATE $table_threads SET thread_id='".$last_thread_id."' WHERE iid='".$last_thread_id."'";
        Database::query($sql);

        if ($last_thread_id) {
            api_item_property_update($courseInfo, TOOL_FORUM_THREAD, $last_thread_id, 'ForumThreadAdded', api_get_user_id());
            api_set_default_visibility($last_thread_id, TOOL_FORUM_THREAD);
        }

        $sql = "INSERT INTO $table_posts (c_id, post_title, post_text, thread_id, forum_id, poster_id, poster_name, post_date, post_notification, post_parent_id, visible)
                VALUES (
                ".$course_id.",
                '".$clean_post_title."',
                '".Database::escape_string($text)."',
                '".Database::escape_string($last_thread_id)."',
                '".Database::escape_string($forum_id)."',
                '".Database::escape_string($user_id)."',
                '".Database::escape_string(stripslashes(isset($poster_name) ? $poster_name : null))."',
                '".Database::escape_string($post_date)."',
                '".Database::escape_string(isset($notice) ? $notice : null)."','0',
                '".Database::escape_string($visible)."')";
        Database::query($sql);
        $last_post_id = Database::insert_id();

        $sql = "UPDATE $table_posts SET post_id='".$last_post_id."' WHERE iid='".$last_post_id."'";
        Database::query($sql);
        if ($my_post_notification == 1) {
            $table_notification = Database::get_course_table(TABLE_FORUM_NOTIFICATION);
            $database_field = 'thread_id';
            $sql = "SELECT * FROM $table_notification WHERE c_id = $course_id AND $database_field = '".Database::escape_string($last_thread_id)."' AND user_id = '".Database::escape_string($user_id)."'";
            $result = Database::query($sql);
            $total = Database::num_rows($result);
            if ($total <= 0) {
                $sql = "INSERT INTO $table_notification (c_id, $database_field, user_id) VALUES (".$course_id.", '".Database::escape_string($last_thread_id)."','".Database::escape_string($user_id)."')";
                $result = Database::query($sql);
            }
        }
        // Now we have to update the thread table to fill the thread_last_post field (so that we know when the thread has been updated for the last time).
        $sql = "UPDATE $table_threads SET thread_last_post='".Database::escape_string($last_post_id)."'
                WHERE c_id = $course_id AND thread_id='".Database::escape_string($last_thread_id)."'";
        $result = Database::query($sql);
        if ($result) {
            return true;
        } else {
            return false;
        }
    }

    public function createPost($c_id, $forum_id, $thread_id, $title, $text, $notice, $user_id, $post_parent) {
        /* LOGIN */
        $platformUser = api_get_user_info($user_id);
        $_user['user_id'] = $platformUser['user_id'];
        $_user['status'] = (isset($platformUser['status']) ? $platformUser['status'] : 5);
        $_user['uidReset'] = true;
        Session::write('_user', $_user);
        $uidReset = true;
        $logging_in = true;
        //Event::eventLogin($_user['user_id']);
        /* Fin login */
        Login::init_user($user_id, true);
        $course_id = $c_id;
        $_course = api_get_course_info_by_id($c_id);
        //$_course = CourseManager::get_course_information_by_id($course_id);
        $table_posts = Database :: get_course_table(TABLE_FORUM_POST);
        $post_date = api_get_utc_datetime();
        $my_post_notification = isset($notice) ? $notice : null;
        $visible = 1;

        $return = [];

        $sql = "INSERT INTO $table_posts (c_id, post_title, post_text, thread_id, forum_id, poster_id, post_date, post_notification, post_parent_id, visible)
                VALUES (
                    ".$course_id.",
                    '".Database::escape_string($title)."',
                    '".Database::escape_string(isset($text) ? ($text) : null)."',
                    '".Database::escape_string($thread_id)."',
                    '".Database::escape_string($forum_id)."',
                    '".$user_id."',
                    '".$post_date."',
                    '".Database::escape_string(isset($my_post_notification) ? $my_post_notification : null)."',
                    '".Database::escape_string(isset($post_parent) ? $post_parent : null)."',
                    '".Database::escape_string($visible)."')";
        Database::query($sql);
        $new_post_id = Database::insert_id();
        $sql = "UPDATE $table_posts SET post_id='".$new_post_id."' WHERE iid='".$new_post_id."'";
        Database::query($sql);
        $reply_info['new_post_id'] = $new_post_id;

        // Update the thread.
        $table_threads = Database :: get_course_table(TABLE_FORUM_THREAD);
        $sql = "UPDATE $table_threads SET thread_replies=thread_replies+1,
                thread_last_post='".Database::escape_string($new_post_id)."',
                thread_date='".Database::escape_string($post_date)."'
                WHERE c_id = $course_id AND  thread_id='".Database::escape_string($thread_id)."'";
        Database::query($sql);

        // Update the forum.
        api_item_property_update($_course, TOOL_FORUM, $forum_id, 'NewMessageInForum', $user_id);

        // Setting the notification correctly.
        if ($my_post_notification == 1) {
            set_notification('thread', $thread_id, true);
        }

        send_notification_mails($forum_id, $thread_id, $reply_info);

        return $new_post_id;
    }

    /**
     * Get ranking of course
     * @param int $c_id The id course
     * @return array the ranking
     */
    public function getRanking($c_id, $s_id)
    {
        global $_configuration;
        $ruta = $_configuration['root_web'];

        $libpath = api_get_path(LIBRARY_PATH);
        require_once $libpath.'course_description.lib.php';
        require_once __DIR__ . '/../../ranking/src/ranking.lib.php';
        require_once __DIR__ . '/../../../main/inc/global.inc.php';
        require_once __DIR__ . '/../../ranking/src/ranking_plugin.class.php';

        //api_protect_course_script(true);
        $plugin = RankingPlugin::create();
        $course_id = $c_id;
        $session_id = $s_id;

        //Se actualiza los resultados al entrar en esta página?
        if ($plugin->get('time_execution') == "true") {
            //Por tiempo toca actualizar las puntuaciones?
            if (checkTimeUpdate($plugin->get('time_refresh'), $course_id, $session_id)) {
                //Borrar registros en la tabla de los usuarios/curso
                DeleteCourseScore($course_id, $session_id);
                //Recorrer usuario por usuario las puntuaciones en las herramientas habilitadas
                AddScoreUsers($course_id, $session_id);
            }
        }
        // Leer Datos y Mostrar tabla
        $info_score = showScoreUser($course_id, $session_id);

        return $info_score;
    }

    public function getDetailsRanking($c_id, $user_id, $s_id)
    {
        require_once __DIR__ . '/../../ranking/config.php';
        require_once __DIR__ . '/../../ranking/src/ranking.lib.php';

        $plugin = RankingPlugin::create();

        $course_id = $c_id;
        $session_id = $s_id;
        $tableScoreUsers = Database::get_main_table(TABLE_RANKING_SCORE_USERS);
        $tableTools = Database::get_main_table(TABLE_RANKING_TOOLS);

        $score_tool = getScoreTool($course_id);
        
        $sql = "SELECT tool, score, participations 
                FROM $tableScoreUsers a LEFT JOIN $tableTools b ON a.tool_id=b.id 
                WHERE user_id='".$user_id."' AND c_id='".$course_id."' AND session_id='".$session_id."' 
                ORDER BY tool_id ASC;";

        $rs = Database::query($sql);
        if (Database::num_rows($rs)>0) {
            $content = '<table class="table-striped" width="100%">';
                $content .= '<tr class="row_odd">';
                    $content .= '<th class="bg-color">'.$plugin->get_lang('Tool').'</th>';
                    $content .= '<th class="ta-center bg-color">'.$plugin->get_lang('Score').'</th>';
                $content .= '</tr>';
            while ($row = Database::fetch_assoc($rs)) {
                if ($score_tool[$row['tool']] != 0) {
                    if ($i%2 == 0) {
                        $content .= '<tr class="row_even">';
                    } else {
                        $content .= '<tr class="row_odd">';
                    }
                    $i = $i + 1;
                    $content .= '<td class="ta-center">'.$plugin->get_lang($row['tool']).'</td>';
                    $content .= '<td class="ta-center">'.$row['score'].'</td>';
                    $content .= '</tr>';
                }
            }
            $content .= '</table>';
        } else {
            $content = $plugin->get_lang('NoResult');
        }

        return $content;
    }
}
