<?php
namespace UCSF\Users;

use REDCap;
use UserRights;
use Exception;
use ExternalModules\AbstractExternalModule;


class Users extends AbstractExternalModule
{
    // System settings
    private $api_token;

    // Request Parameters
    public $token, $request, $username;

    // Common Response Messages
    public $cannot_modify_data_error_message;
    public $success_message = 'Success';
    public $missing_username_message = 'Missing username';
    public $invalid_time_format_message = 'Time invalid format.  Needs to be YYYY-MM-DD hh:mm:ss';

    /**
     * This function wraps the handling of all API requests
     *
     * @return array|bool|false|string
     */
    public function parseRequest()
    {
        // CONVERT RAW POST TO PHP POST
        if (empty($_POST)) $_POST = json_decode(file_get_contents('php://input'), true);
        
        // FILTER BY IP
        $this->applyIpFilter();

        $this->token = null;
        $headers = apache_request_headers();
        if(isset($headers['Authorization'])){
            $matches = array();
            preg_match('/Bearer (.*)/', $headers['Authorization'], $matches);
            if(isset($matches[1])){
                $this->token = $matches[1];
            }
        }

        // PARSE POST PARAMETERS
        $this->request    = empty($_POST['request'])    ? null : $_POST['request'];
        $this->username      = empty($_POST['username'])      ? null : $_POST['username'];
        $this->time      = empty($_POST['time'])      ? null : $_POST['time'];
        $this->institution      = empty($_POST['institution'])      ? null : $_POST['institution'];
        $this->sponsor      = empty($_POST['sponsor'])      ? null : $_POST['sponsor'];
        $this->comments      = empty($_POST['comments'])      ? null : $_POST['comments'];

        // VERIFY TOKEN
        $this->api_token = $this->getSystemSetting('users-api-token');
        if (empty($this->token) || $this->token != $this->api_token) {
            return $this->returnError("Invalid API Token");
        }

        $this->cannot_modify_data_error_message = "Cannot modify data for user: " . $this->username;

        // If all checks are satisfied, process the request.
        $this->performRequest();
    }


    /**
     * All checks were satisfied, perform the actual request.
     *
     * @return array|bool|false|string
     */
    public function performRequest() {
        $result = array();
        if (empty($this->username)) {
            return $this->returnError($this->missing_username_message);
        }
        switch($this->request) {
            case "userdetail";
                $result = $this->getUserDetailByUsername($this->username);
                break;
            case "setsuspension";
                if (empty($this->time)) {
                    return $this->returnError("Missing time");
                }
                if (preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',$this->time)) {
                    $result = $this->setUserSuspensionTime($this->username, $this->time);
                } else {
                    return $this->returnError($this->invalid_time_format_message);
                }
                break;
            case "removesuspension";
                $result = $this->removeSuspensionTime($this->username);
                break;
            case "suspend";
                $result = $this->suspendUser($this->username);
                break;
            case "setexpiration";
                if (empty($this->time)) {
                    return $this->returnError("Missing time");
                }
                if (preg_match('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/',$this->time)) {
                    $result = $this->setUserExpirationTime($this->username, $this->time);
                } else {
                  return $this->returnError($this->invalid_time_format_message);
                }
                break;
            case "removeexpiration";
                $result = $this->removeExpirationTime($this->username);
                break;
            case "expire";
                $result = $this->expireUser($this->username);
                break;
            case "deactivate";
                $result = $this->deactivate($this->username);
                break;
            case "activate";
                $result = $this->activate($this->username);
                break;
            case "setinstitution";
                if (empty($this->institution)) {
                    return $this->returnError("Missing institution");
                }
                if (strlen($this->institution)>255) {
                    return $this->returnError("Institution is too long.  Needs to be less than 256 characters.");
                }
                $result = $this->setInstitution($this->username, $this->institution);
                break;
            case "setsponsor";
                if (empty($this->sponsor)) {
                    return $this->returnError("Missing sponsor");
                }
                if (strlen($this->sponsor)>255) {
                    return $this->returnError("Sponsor is too long.  Needs to be less than 256 characters.");
                }
                $result = $this->setSponsor($this->username, $this->sponsor);
                break;
            case "setcomments";
                if (empty($this->comments)) {
                    return $this->returnError("Missing comments");
                }
                if (strlen($this->comments)>65535) {
                    return $this->returnError("Comments is too long.  Needs to be less than 65535 characters.");
                }
                $result = $this->setComments($this->username, $this->comments);
                break;
            case "addcomments";
                if (empty($this->comments)) {
                    return $this->returnError("Missing comments");
                }
                if (strlen($this->comments)>65535) {
                    return $this->returnError("Comments is too long.  Needs to be less than 65535 characters.");
                }
                $result = $this->addComments($this->username, $this->comments);
                break;
            default:
                return $this->returnError("Invalid Request", 400);
                break;
        }

        // Output Results
        header("Content-type: application/json");
        echo json_encode($result);
    }

    /**
     * Apply the IP filter if set. If the IP address is not specified in the system IP ranges, send an email to the
     * alert email address (also specified in the system configuration).
     *
     * @return null
     */
    function applyIpFilter() {

        $ip_addr = trim($_SERVER['REMOTE_ADDR']);

        // APPLY IP FILTER
        $ip_filter = $this->getSystemSetting('ip');
        if (!empty($ip_filter) && !empty($ip_filter[0])) {
            $isValid = false;
            foreach ($ip_filter as $filter) {
                if (self::ipCIDRCheck($filter, $ip_addr)) {
                    $isValid = true;
                    break;
                }
            }
            // Exit - invalid IP
            if (!$isValid) {

                // Send email to designated user if IP is invalid
                $emailTo = $this->getSystemSetting('alert-email');
                if (!empty($emailTo)) {
                    $emailFrom = $emailTo;
                    $subject = "Unauthorized IP trying to access Users API";
                    $body = "IP address $ip_addr is trying to access the Users API and is not in the approved IP range.";
                    $status = REDCap::email($emailTo, $emailFrom, $subject, $body);
                }

                // Return error
                return $this->returnError("Invalid source IP: " . $ip_addr);
            }
        }
    }

    /**
     * Utility function to verify IP is from valid range if specified
     *
     * e.g. 192.168.123.1 = 192.168.123.1/30
     * @param $CIDR
     * @return bool true | false
     */
    public static function ipCIDRCheck ($CIDR, $ip) {
        // Convert IPV6 localhost into IPV4
        if ($ip == "::1") $ip = "127.0.0.1";
        if(strpos($CIDR, "/") === false) $CIDR .= "/32";
        list ($net, $mask) = explode("/", $CIDR);
        $ip_net  = ip2long($net);
        $ip_mask = ~((1 << (32 - $mask)) - 1);
        $ip_ip = ip2long($ip);
        $ip_ip_net = $ip_ip & $ip_mask;
        return ($ip_ip_net == $ip_net);
    }

    /**
     * Return an error message
     *
     * @param string    $error_message
     * @param int       $http_code
     */
    function returnError($error_message, $http_code=400) {
        header("Content-type: application/json");
        http_response_code($http_code);
        echo json_encode(["error" => $error_message]);
        return ["error" => $error_message];
    }

    /**
     * Returns TRUE if the user's data can be modified.
     * Returns FALSE if the user's data cannot be modified.
     * Data for privileged users cannot be modified through this API.
     * 
     * @param string    $username
     */
    function canModifyData($username) {
        $sql=   "select * from redcap_user_information
                where username = '" . db_real_escape_string($username) . "';";
        $result = TRUE;
        try {
            $q = $this->query($sql);
            while ($row = db_fetch_assoc($q)) {
                if (    $row['super_user'] == 1 
                    ||  $row['account_manager'] == 1
                    ||  $row['access_system_config'] == 1
                    ||  $row['access_system_upgrade'] == 1
                    ||  $row['access_external_module_install'] == 1
                    ||  $row['admin_rights'] == 1
                    ||  $row['access_admin_dashboards'] == 1
                ) {
                    $result = FALSE;
                } 
            }
        } catch (Exception $e) {
            REDCap::logEvent("Failed to determine if data can be modified for user: " . $username, $e->getMessage(), $sql);
            $result = FALSE;
        }
        return $result;
    }
    

    /**
     * Returns the user details associated with the given username
     * 
     * @param string    $username
     */
    function getUserDetailByUsername($username) {
        $results = array();
        $sql=   "select * from redcap_user_information
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            while ($row = db_fetch_assoc($q)) {
                // Remove sensitive information
                unset($row['two_factor_auth_secret']);
                unset($row['api_token']);
                $results = $row;
            }
        } catch (Exception $e) {
            $error_message = "Failed to get user details for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Sets the suspension time for the given user
     * 
     * @param string    $username
     * @param string    $time
     */
    function setUserSuspensionTime($username, $time) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $sql=   "update redcap_user_information
                    set user_suspended_time = '" . db_real_escape_string($time) . "'
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to set user suspension time for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Removes the suspension time for the given user
     * 
     * @param string    $username
     */
    function removeSuspensionTime($username) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_suspended_time = NULL
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to remove suspension for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Suspends the given user by setting the user suspension time to now()
     * 
     * @param string    $username
     */
    function suspendUser($username) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_suspended_time = now()
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to suspend user: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Sets the expiration time for the given user
     * 
     * @param string    $username
     * @param string    $time
     */
    function setUserExpirationTime($username, $time) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_expiration = '" . db_real_escape_string($time) . "'
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to set expiration for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Removes expiration for a given user by setting user expiration to null
     * 
     * @param string    $username
     */
    function removeExpirationTime($username) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_expiration = NULL
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to remove expiration time for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Expires the given user by setting the user's expiration to now()
     * 
     * @param string    $username
     */
    function expireUser($username) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_expiration = now()
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to expire user: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Deactivates the given user by suspending and expiring it
     * 
     * @param string    $username
     */
    function deactivate($username) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $this->expireUser($username);
        $this->suspendUser($username);
        return $this->success_message;
    }

    /**
     * Activates the given user by removing its suspension time and expiration time
     * 
     * @param string    $username
     */
    function activate($username) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $this->removeExpirationTime($username);
        $this->removeSuspensionTime($username);
        return $this->success_message;
    }

    /**
     * Sets the institution for the given user
     * 
     * @param string    $username
     * @param string    $institution
     */
    function setInstitution($username, $institution) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_inst_id = '" . db_real_escape_string($institution)  ."'
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to set institution for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Sets the sponsor for the given user
     * 
     * @param string    $username
     * @param string    $sponsor
     */
    function setSponsor($username, $sponsor) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $sql=   "update redcap_user_information
                    set user_sponsor = '" . db_real_escape_string($sponsor)  ."'
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to set sponsor for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Sets the comments for the given user
     * 
     * @param string    $username
     * @param string    $comments
     */
    function setComments($username, $comments) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $results = array();
        $sql=   "update redcap_user_information
                    set user_comments = '" . db_real_escape_string($comments)  ."'
                    where username = '" . db_real_escape_string($username) . "';";
        try {
            $q = $this->query($sql);
            $results = $this->success_message;
        } catch (Exception $e) {
            $error_message = "Failed to set comments for: " . $username;
            REDCap::logEvent($error_message, $e->getMessage(), $sql);
            return $this->returnError($error_message, 500);
        }
        return $results;
    }

    /**
     * Add comments for the given user
     * 
     * @param string    $username
     * @param string    $comments
     */
    function addComments($username, $comments) {
        if (!($this->canModifyData($username))) {
            return $this->returnError($this->cannot_modify_data_error_message);
        }
        $user_details = $this->getUserDetailByUsername($username);
        if ($user_details['user_comments'] == null) {
            $results = $this->setComments($username, $comments);
        } else {
            $sql=   "update redcap_user_information
                        set user_comments = CONCAT(user_comments,'" . db_real_escape_string($comments)  ."')
                        where username = '" . db_real_escape_string($username) . "';";
            try {
                $q = $this->query($sql);
                $results = $this->success_message;
            } catch (Exception $e) {
                $error_message = "Failed to add comments for: " . $username;
                REDCap::logEvent($error_message, $e->getMessage(), $sql);
                return $this->returnError($error_message, 500);
            }
        }
        return $results;
    }
}