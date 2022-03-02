<?php namespace UCSF\Users;

use Exception;

const ADMIN_USER_NAME = 'phpunit_admin_user';
const NORMAL_USER_NAME = 'phpunit_normal_user';

class UsersTest extends BaseTest {

    public function setUp():void{
        parent::setUp();
        // Setup test users
        $this->query('insert into redcap_user_information (username, super_user) values (?, 1);', ADMIN_USER_NAME);
        $this->query('insert into redcap_user_information (username) values (?);', NORMAL_USER_NAME);
    }

    public function teardown():void {
        // Remove test users
        $this->query('delete from redcap_user_information where username = ?', ADMIN_USER_NAME);
        $this->query('delete from redcap_user_information where username = ?', NORMAL_USER_NAME);
    }

    public function testGetUserDetails() {        
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $admin_user_details = $this->module->getUserDetailByUsername(ADMIN_USER_NAME);

        $required_details = array('username', 'user_email','user_email2','user_email3','user_phone','user_phone_sms','user_firstname',
            'user_lastname','user_inst_id','super_user','account_manager','access_system_config','access_system_upgrade','access_external_module_install',
            'admin_rights', 'access_admin_dashboards', 'user_creation','user_firstvisit','user_firstactivity','user_lastactivity','user_lastlogin',
            'user_suspended_time','user_expiration','user_access_dashboard_view','user_access_dashboard_email_queued', 'user_sponsor', 'user_comments',
            'allow_create_db', 'email_verify_code', 'email2_verify_code', 'email3_verify_code', 'datetime_format', 'number_format_decimal', 
            'number_format_thousands_sep', 'csv_delimiter', 'display_on_email_users', 'two_factor_auth_twilio_prompt_phone', 'two_factor_auth_code_expiration',
            'messaging_email_preference', 'messaging_email_urgent_all', 'messaging_email_ts', 'messaging_email_general_system', 'messaging_email_queue_time',
            'ui_state', 'api_token_auto_request', 'fhir_data_mart_create_project');

        // Check expected values
        foreach ($required_details as $detail) {
            $this->assertTrue(array_key_exists($detail, $normal_user_details));
            $this->assertTrue(array_key_exists($detail, $admin_user_details));
        }

        $sensitive_details = array('two_factor_auth_secret', 'api_token');

        // Check that sensitive data was removed
        foreach ($sensitive_details as $detail) {
            $this->assertFalse(array_key_exists($detail, $normal_user_details));
            $this->assertFalse(array_key_exists($detail, $admin_user_details));
        }

        $this->assertEquals($normal_user_details['super_user'], 0);
        $this->assertEquals($admin_user_details['super_user'], 1);
    }

    public function testSetSponsor() {    
        $sponsor = 'Test Sponsor';
        $response = $this->module->setSponsor(NORMAL_USER_NAME, $sponsor);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_sponsor'], $sponsor);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testSetUserSuspensionTime() {
        $time = '2022-02-07 02:41:00';
        $response = $this->module->setUserSuspensionTime(NORMAL_USER_NAME, $time);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_suspended_time'], $time);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testRemoveSuspension() {
        $time = '2022-02-07 02:41:00';
        $response = $this->module->setUserSuspensionTime(NORMAL_USER_NAME, $time);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_suspended_time'], $time);
        $this->assertEquals($response, $this->module->success_message);

        $response = $this->module->removeSuspensionTime(NORMAL_USER_NAME);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_suspended_time'], null);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testSuspend() {
        $response = $this->module->suspendUser(NORMAL_USER_NAME);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        if (strtotime($normal_user_details['user_suspended_time']) == strtotime('now')) {
            $this->assertEquals($response, $this->module->success_message);
        } else {
            throw new Exception('User suspension time is not now()');
        }
    }

    public function testSetExpiration() {
        $time = '2022-02-07 02:41:00';
        $response = $this->module->setUserExpirationTime(NORMAL_USER_NAME, $time);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_expiration'], $time);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testRemoveExpiration() {
        $time = '2022-02-07 02:41:00';
        $response = $this->module->setUserExpirationTime(NORMAL_USER_NAME, $time);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_expiration'], $time);
        $this->assertEquals($response, $this->module->success_message);

        $response = $this->module->removeExpirationTime(NORMAL_USER_NAME);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_expiration'], null);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testExpire(){
        $response = $this->module->expireUser(NORMAL_USER_NAME);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        if (strtotime($normal_user_details['user_expiration']) == strtotime('now')) {
            $this->assertEquals($response, $this->module->success_message);
        } else {
            throw new Exception('User expiration time is not now()');
        }
    }

    public function testDeactivate() {
        $response = $this->module->deactivate(NORMAL_USER_NAME);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        if (strtotime($normal_user_details['user_expiration']) != strtotime('now')) {
            throw new Exception('User expiration time is not now()');
        }
        if (strtotime($normal_user_details['user_suspended_time']) != strtotime('now')) {
            throw new Exception('User suspension time is not now()');
        }
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testActivate() {
        $response = $this->module->deactivate(NORMAL_USER_NAME);
        $this->assertEquals($response, $this->module->success_message);
        $response = $this->module->activate(NORMAL_USER_NAME);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_expiration'], null);
        $this->assertEquals($normal_user_details['user_suspended_time'], null);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testSetInstitution() {
        $institution = 'Test Institution';
        $response = $this->module->setInstitution(NORMAL_USER_NAME, $institution);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_inst_id'], $institution);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testSetComments() {
        $comments = 'Test Comments';
        $response = $this->module->setComments(NORMAL_USER_NAME, $comments);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_comments'], $comments);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testAddComments() {
        $comments = 'Test Comments';
        $response = $this->module->addComments(NORMAL_USER_NAME, $comments);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_comments'], $comments);
        $this->assertEquals($response, $this->module->success_message);

        $response = $this->module->addComments(NORMAL_USER_NAME, $comments);
        $normal_user_details = $this->module->getUserDetailByUsername(NORMAL_USER_NAME);
        $this->assertEquals($normal_user_details['user_comments'], $comments . $comments);
        $this->assertEquals($response, $this->module->success_message);
    }

    public function testCanModifyData() {
        $response = $this->module->canModifyData(NORMAL_USER_NAME);
        $this->assertTrue($response);
        $response = $this->module->canModifyData(ADMIN_USER_NAME);
        $this->assertFalse($response);
    }

    public function testPerformRequest() {
        // Test Missing Username
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], $this->module->missing_username_message);

        $this->module->username = NORMAL_USER_NAME;
        
        // Test Invalid Request
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Invalid Request');

        // Test Missing Time
        $this->module->request = 'setsuspension';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Missing time');
        $this->module->request = 'setexpiration';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Missing time');

        $this->module->time = 'invalid_format';
        // Test invalid time format
        $this->module->request = 'setsuspension';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], $this->module->invalid_time_format_message);
        $this->module->request = 'setexpiration';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], $this->module->invalid_time_format_message);

        // Test Missing institution
        $this->module->request = 'setinstitution';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Missing institution');

        // Test Missing sponsor
        $this->module->request = 'setsponsor';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Missing sponsor');

        // Test Missing comments
        $this->module->request = 'setcomments';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Missing comments');
        $this->module->request = 'addcomments';
        $response = $this->module->performRequest();
        $this->assertEquals($response['error'], 'Missing comments');
    }
}