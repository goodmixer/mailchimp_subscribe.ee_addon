<?php if ( ! defined('BASEPATH')) exit('Invalid file request');

/**
 * Handles all the API and database communication for MailChimp Subscribe.
 *
 * @author		Stephen Lewis <addons@experienceinternet.co.uk>
 * @link 		http://experienceinternet.co.uk/software/mailchimp-subscribe/
 * @package		MailChimp Subscribe
 * @version		2.0.0b2
 */

require_once PATH_THIRD .'mailchimp_subscribe/library/MCAPI.class' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Base' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Api_account' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Exceptions' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Interest_group' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Mailing_list' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Merge_variable' .EXT;
require_once PATH_THIRD .'mailchimp_subscribe/library/MCS_Settings' .EXT;

class Mailchimp_model extends CI_Model {
	
	/* --------------------------------------------------------------
	 * PRIVATE PROPERTIES
	 * ------------------------------------------------------------ */
	
	/**
	 * The API user account.
	 *
	 * @access	private
	 * @var		MCS_Api_account
	 */
	private $_api_account = NULL;
	
	/**
	 * The API connector.
	 *
	 * @access	private
	 * @var		object
	 */
	private $_connector = NULL;
	
	/**
	 * ExpressionEngine object.
	 *
	 * @access	private
	 * @var		object
	 */
	private $_ee;
	
	/**
	 * The extension class name.
	 *
	 * @access	private
	 * @var		string
	 */
	private $_extension_class = '';
	
	/**
	 * The extension version.
	 *
	 * @access	private
	 * @var		string
	 */
	private $_version = '';
	
	/**
	 * Mailing lists.
	 *
	 * @access	private
	 * @var		array
	 */
	private $_mailing_lists = array();
	
	
	/**
	 * Member fields.
	 *
	 * @access	private
	 * @var		array
	 */
	private $_member_fields = array();
	
	/**
	 * The extension settings.
	 *
	 * @access	private
	 * @var		MCS_Settings
	 */
	private $_settings = NULL;
	
	/**
	 * The site ID.
	 *
	 * @access	private
	 * @var		string
	 */
	private $_site_id = '1';
	
	/**
	 * The `theme` folder URL for this add-on.
	 *
	 * @access	private
	 * @var		string
	 */
	private $_theme_folder_url = '';
	
	/**
	 * The 'view' settings. That is, the saved settings, plus any additional
	 * mailing lists, all wrapped up in a neat little MCS_Settings object, for
	 * use in the view.
	 *
	 * @access	private
	 * @var		MCS_Settings
	 */
	private $_view_settings = NULL;
	
	
	
	/* --------------------------------------------------------------
	 * PUBLIC METHODS
	 * ------------------------------------------------------------ */
	
	/**
	 * Class constructor.
	 *
	 * @access	public
	 * @return	void
	 */
	public function __construct()
	{
		$this->_extension_class = 'Mailchimp_subscribe_ext';
		$this->_version			= '2.0.0b2';
		
		$this->_ee 		=& get_instance();
		$this->_site_id = $this->_ee->config->item('site_id');
		
		/**
		 * Annoyingly, this method is still called, even if the extension
		 * isn't installed. We need to check if such nonsense is afoot,
		 * and exit promptly if so.
		 */
		
		if ( ! isset($this->_ee->extensions->version_numbers[$this->_extension_class]))
		{
			return;
		}
		
		// Load the settings.
		$this->_load_settings_from_db();
		
		// Load the member fields from the database.
		$this->_load_member_fields_from_db();
	}
	
	
	/**
	 * Activates the extension.
	 *
	 * @access	public
	 * @return	void
	 */
	public function activate_extension()
	{
		$hooks = array(
			array(
				'hook'		=> 'cp_members_member_create',
				'method'	=> 'cp_members_member_create',
				'priority'	=> 10
			),
			array(
				'hook'		=> 'cp_members_validate_members',
				'method'	=> 'cp_members_validate_members',
				'priority'	=> 10
			),
			array(
				'hook'		=> 'member_member_register',
				'method'	=> 'member_member_register',
				'priority'	=> 10
			),
			array(
				'hook'		=> 'member_register_validate_members',
				'method'	=> 'member_register_validate_members',
				'priority'	=> 10
			),
			array(
				'hook'		=> 'user_edit_end',
				'method'	=> 'user_edit_end',
				'priority'	=> 10
			),
			array(
				'hook'		=> 'user_register_end',
				'method'	=> 'user_register_end',
				'priority'	=> 10
			)
		);
		
		foreach ($hooks AS $hook)
		{
			$this->_ee->db->insert(
				'extensions',
				array(
					'class'		=> $this->_extension_class,
					'enabled'	=> 'y',
					'hook'		=> $hook['hook'],
					'method'	=> $hook['method'],
					'priority'	=> $hook['priority'],
					'version'	=> $this->_version
				)
			);
		}
		
		// Create the settings table.
		$fields = array(
			'site_id' => array(
				'constraint'	=> 8,
				'null'			=> FALSE,
				'type'			=> 'int',
				'unsigned'		=> TRUE
			),
			'settings' => array(
				'null'			=> FALSE,
				'type'			=> 'text',
			)
		);
		
		$this->load->dbforge();
		$this->_ee->dbforge->add_field($fields);
		$this->_ee->dbforge->add_key('site_id', TRUE);
		$this->_ee->dbforge->create_table('mailchimp_subscribe_settings', TRUE);
		
		// Create the 'error log' table.
		$fields = array(
			'error_log_id' => array(
				'auto_increment' => TRUE,
				'constraint'	=> 10,
				'null'			=> FALSE,
				'type'			=> 'int',
				'unsigned'		=> TRUE
			),
			'site_id' => array(
				'constraint'	=> 5,
				'default'		=> 1,
				'null'			=> FALSE,
				'type'			=> 'int',
				'unsigned'		=> TRUE
			),
			'error_date' => array(
				'constraint'	=> 10,
				'null'			=> FALSE,
				'type'			=> 'int',
				'unsigned'		=> TRUE
			),
			'error_code' => array(
				'constraint'	=> 10,
				'null'			=> TRUE,
				'type'			=> 'varchar'
			),
			'error_message' => array(
				'constraint'	=> 255,
				'null'			=> TRUE,
				'type'			=> 'varchar'
			)
		);
		
		$this->_ee->dbforge->add_field($fields);
		$this->_ee->dbforge->add_key('error_log_id', TRUE);
		$this->_ee->dbforge->add_key('site_id', FALSE);
		$this->_ee->dbforge->create_table('mailchimp_subscribe_error_log', TRUE);
	}
	
	
	/**
	 * Disables the extension.
	 *
	 * @access	public
	 * @return	void
	 */
	public function disable_extension()
	{
		$this->_ee->db->delete('extensions', array('class' => $this->_extension_class));
		
		$this->load->dbforge();
		$this->_ee->dbforge->drop_table('mailchimp_subscribe_settings');
		$this->_ee->dbforge->drop_table('mailchimp_subscribe_error_log');
	}
	
	
	/**
	 * Returns the API account details.
	 *
	 * @access	public
	 * @return	array
	 */
	public function get_api_account()
	{
		if ( ! $this->_api_account)
		{
			try
			{
				$this->_load_account_from_api();
			}
			catch (MCS_exception $exception)
			{
				$this->log_error($exception->getMessage(), $exception->getCode());
			}
		}
		
		return $this->_api_account;
	}
	
	
	/**
	 * Returns the error log.
	 *
	 * @access	public
	 * @return	array
	 */
	public function get_error_log()
	{
		$db_error_log = $this->_ee->db
			->order_by('error_log_id', 'desc')
			->get_where('mailchimp_subscribe_error_log', array('site_id' => $this->_site_id));
		
		return $db_error_log->result_array();
	}
	
	
	/**
	 * Retrieves the available mailing lists from the API.
	 *
	 * @access	public
	 * @return	array
	 */
	public function get_mailing_lists()
	{
		if ( ! $this->_mailing_lists)
		{
			try
			{
				$this->_load_mailing_lists_from_api();
			}
			catch (MCS_exception $exception)
			{
				$this->log_error($exception->getMessage(), $exception->getCode());
			}
		}
		
		return $this->_mailing_lists;
	}
	
	
	/**
	 * Returns matching members. Pretty rudimentary at present; simply retrieves
	 * all the exp_members and exp_member_data fields. Criteria are assumed to refer
	 * to the exp_members table.
	 *
	 * @access	public
	 * @param	array	$criteria	Associative array of criteria.
	 * @return	array
	 */
	public function get_members(Array $criteria = array())
	{
		foreach ($criteria AS $key => $val)
		{
			is_array($val)
				? $this->_ee->db->where_in('members.' .$key, $val)
				: $this->_ee->db->where('members.' .$key, $val);
		}
		
		$this->_ee->db->join('member_data', 'member_data.member_id = members.member_id', 'inner');
		
		$db_members = $this->_ee->db->get('members');
		return $db_members->result_array();
	}
	
	
	/**
	 * Returns the available member fields.
	 *
	 * @access	public
	 * @return	array
	 */
	public function get_member_fields()
	{
		return $this->_member_fields;
	}
	
	
	/**
	 * Returns the site settings.
	 *
	 * @access	public
	 * @return	array
	 */
	public function get_settings()
	{
		return $this->_settings;
	}
	
	
	/**
	 * Returns the `theme` folder URL.
	 *
	 * @access	public
	 * @return	string
	 */
	public function get_theme_url()
	{
		if ( ! $this->_theme_folder_url)
		{
			$this->_theme_folder_url = $this->_ee->config->item('theme_folder_url');
			$this->_theme_folder_url .= substr($this->_theme_folder_url, -1) == '/'
				? 'third_party/mailchimp_subscribe/'
				: '/third_party/mailchimp_subscribe/';
		}
		
		return $this->_theme_folder_url;
	}
	
	
	/**
	 * Returns the extension version.
	 *
	 * @access	public
	 * @return	string
	 */
	public function get_version()
	{
		return $this->_version;
	}
	
	
	/**
	 * Returns the "view" settings. That is, the saved settings, plus
	 * any additional mailing lists, all wrapped up in a neat little
	 * MCS_Settings object, for use in the view.
	 *
	 * @access	public
	 * @return	void
	 */
	public function get_view_settings()
	{
		if ( ! $this->_view_settings)
		{
			// Base unsubscribe URL.
			$unsubscribe_url = 'http://list-manage.com/unsubscribe?u=%s&amp;id=%s';
			
			$saved_settings = $this->get_settings();
			$mailing_lists	= $this->get_mailing_lists();
			$api_account	= $this->get_api_account();
		
			// Basic view settings.
			$view_settings = new MCS_Settings($saved_settings->to_array());
			$view_settings->reset_mailing_lists();
		
			// Loop through the mailing lists.
			foreach ($mailing_lists AS $mailing_list)
			{
				$old_list = $saved_settings->get_mailing_list($mailing_list->id)
					? $saved_settings->get_mailing_list($mailing_list->id)
					: new MCS_Mailing_list();
				
				// Create the new mailing list.
				$new_list = new MCS_Mailing_list(array(
					'active'			=> $old_list->active,
					'id'				=> $mailing_list->id,
					'name'				=> $mailing_list->name,
					'trigger_field'		=> $old_list->trigger_field,
					'trigger_value'		=> $old_list->trigger_value,
					'unsubscribe_url'	=> $api_account->user_id
											? sprintf($unsubscribe_url, $api_account->user_id, $mailing_list->id)
											: ''
				));
			
				// Interest Groups.
				foreach ($mailing_list->interest_groups AS $key => $val)
				{
					$temp_group = $old_list->get_interest_group($key)
						? $old_list->get_interest_group($key)
						: new MCS_Interest_group();
					
					$temp_group->id 	= $val->id;
					$temp_group->name 	= $val->name;
				
					$new_list->add_interest_group($temp_group);
				}
			
				// Merge Variables.
				foreach ($mailing_list->merge_variables AS $key => $val)
				{
					$temp_var = $old_list->get_merge_variable($key)
						? $old_list->get_merge_variable($key)
						: new MCS_Merge_variable();
					
					$temp_var->tag	= $val->tag;
					$temp_var->name	= $val->name;
				
					$new_list->add_merge_variable($temp_var);
				}
			
				// Update the saved settings.
				$view_settings->add_mailing_list($new_list);
			}
		
			$this->_view_settings = $view_settings;
		}
		
		return $this->_view_settings;
	}
	
	
	/**
	 * Logs an error to the database.
	 *
	 * @access	public
	 * @param	string		$message	The error message.
	 * @param	string		$code		The error code.
	 * @return	void
	 */
	public function log_error($message = '', $code = '')
	{
		$this->_ee->db->insert(
			'mailchimp_subscribe_error_log',
			array(
				'site_id'		=> $this->_site_id,
				'error_date'	=> time(),
				'error_code'	=> $code,
				'error_message'	=> $message
			)
		);
	}
	
	
	/**
	 * Saves the site settings.
	 *
	 * @access	public
	 * @return	bool
	 */
	public function save_settings()
	{
		$settings = addslashes(serialize($this->_settings->to_array()));
		
		$this->_ee->db->delete('mailchimp_subscribe_settings', array('site_id' => $this->_site_id));
		$this->_ee->db->insert('mailchimp_subscribe_settings', array('site_id' => $this->_site_id, 'settings' => $settings));
		
		return TRUE;
	}
	
	
	/**
	 * Subscribes a member to the active mailing list(s).
	 *
	 * @access	public
	 * @param 	string 		$member_id 		The member ID.
	 * @return 	void
	 */	
	public function subscribe_member($member_id)
	{
		try
		{
			$this->_update_member_subscriptions($member_id, FALSE);
		}
		catch (MCS_Exception $exception)
		{
			$this->log_error($exception->getMessage(), $exception->getCode());
		}
	}
	
	
	/**
	 * Updates the extension.
	 *
	 * @access	public
	 * @param 	string		$current_version		The current version.
	 * @return	bool
	 */
	public function update_extension($current_version = '')
	{
		if ( ! $current_version OR $current_version == $this->_version)
		{
			return FALSE;
		}
		
		// Update the version number.
		if ($current_version < $this->_version)
		{
			$this->_ee->db->update(
				'extensions',
				array('version' => $this->_version),
				array('class' => $this->_extension_class)
			);
		}
		
		return TRUE;
	}
	
	
	/**
	 * Updates a member's mailing list subscriptions.
	 *
	 * @access	public
	 * @param	string		$member_id		The member ID.
	 * @return	void
	 */
	public function update_member_subscriptions($member_id = '')
	{
		try
		{
			$this->_update_member_subscriptions($member_id, TRUE);
		}
		catch (MCS_Exception $exception)
		{
			$this->log_error($exception->getMessage(), $exception->getCode());
		}
	}
	
	
	/**
	 * Updates the settings from the input.
	 *
	 * @access	public
	 * @param 	array 		$settings		The settings to update.
	 * @return	array
	 */
	public function update_settings_from_input()
	{
		$settings = $this->_settings;
		
		// Update the API key. This is the easy bit.
		$settings->api_key = ($this->_ee->input->get_post('api_key') !== FALSE)
			? $this->_ee->input->get_post('api_key')
			: $settings->api_key;
			
		// The mailing lists require rather more work.
		if (is_array($lists = $this->_ee->input->get_post('mailing_lists')))
		{
			$settings->mailing_lists = array();
			
			foreach ($lists AS $list_id => $list_settings)
			{
				if ( ! isset($list_settings['checked']))
				{
					continue;
				}
				
				// Basic list information.
				$list 				= new MCS_Mailing_list();
				$list->active		= 'y';
				$list->id 			= $list_id;
				$list->trigger_field = isset($list_settings['trigger_field']) ? $list_settings['trigger_field'] : '';
				$list->trigger_value = isset($list_settings['trigger_value']) ? $list_settings['trigger_value'] : '';
				
				// Interest groups.
				if (isset($list_settings['interest_groups']) && is_array($list_settings['interest_groups']))
				{
					foreach ($list_settings['interest_groups'] AS $mailchimp_field_id => $member_field_id)
					{
						$list->add_interest_group(new MCS_Interest_group(array(
							'id' 				=> $mailchimp_field_id,
							'member_field_id' 	=> $member_field_id
						)));
					}
				}
				
				// Merge variables.
				if (isset($list_settings['merge_variables']) && is_array($list_settings['merge_variables']))
				{
					foreach ($list_settings['merge_variables'] AS $mailchimp_field_id => $member_field_id)
					{
						$list->add_merge_variable(new MCS_Merge_variable(array(
							'tag' 				=> $mailchimp_field_id,
							'member_field_id' 	=> $member_field_id
						)));
					}
				}
				
				$settings->add_mailing_list($list);
			}
		}
		
		$this->_settings = $settings;
		
		// The view settings are probably out-of-date, so reset them.
		$this->_view_settings = NULL;
	}
	
	
	
	/* --------------------------------------------------------------
	 * PRIVATE METHODS
	 * ------------------------------------------------------------ */
	
	/**
	 * Handles all API requests.
	 *
	 * @access	private
	 * @param	string	$method	The API method to call.
	 * @param	array	$params	An additional parameters to include in the API call.
	 * @return	void
	 */
	private function _call_api($method = '', Array $params = array())
	{
		// Do we have a valid connector?
		if ( ! $this->_initialize_connector())
		{
			throw new MCS_Data_exception('Unable to initialize connector object.');
		}
		
		if ( ! method_exists($this->_connector, $method))
		{
			throw new MCS_Api_exception('Unknown API method "' .$method .'".');
		}
		
		$result = call_user_func_array(array($this->_connector, $method), $params);
		
		// Was the connector method called successfully?
		if ($result === FALSE)
		{
			throw new MCS_Api_exception($this->_connector->errorMessage, $this->_connector->errorCode);
		}
		
		// Was the API method called successfully.
		if ($this->_connector->errorCode)
		{
			throw new MCS_Api_exception($this->_connector->errorMessage, $this->_connector->errorCode);
		}
		
		return $result;
	}
	
	
	/**
	 * Attempts to initialise the MailChimp API 'connector'.
	 *
	 * @access	private
	 * @return	bool
	 */
	private function _initialize_connector()
	{
		// Once the connector has been created, we're good.
		if ($this->_connector)
		{
			return TRUE;
		}
		
		// If we have an API key, create the connector.
		$this->_connector = $this->_settings->api_key
			? new MCAPI($this->_settings->api_key)
			: NULL;
		
		return ( ! is_null($this->_connector));
	}
	
	
	/**
	 * Retrieves the user account details from the API.
	 *
	 * @access	private
	 * @return	void
	 */
	private function _load_account_from_api()
	{
		/**
		 * Reset the account details. We do this first in case
		 * the API call throws an exception.
		 */
		
		$this->_api_account = new MCS_Api_account();
		
		// Make the API call.
		$api_account = $this->_call_api('getAccountDetails');
		$this->_api_account->populate_from_array($api_account);
	}
	
	
	/**
	 * Retrieves the mailing lists from the API.
	 *
	 * @access	private
	 * @return	void
	 */
	private function _load_mailing_lists_from_api()
	{
		// Reset the mailing lists.
		$this->_mailing_lists = array();
		
		// Make the API call.
		$result = $this->_call_api('lists');
		
		// Parse the results.
		$lists = array();
		
		foreach ($result AS $r)
		{
			/**
			 * Retrieve the interest 'groupings'. MailChimp now supports multiple interest groups.
			 * If the list does not have any interest groups, error code 211 is returned, and
			 * an exception is thrown.
			 *
			 * We don't want this to bring everything crashing to a halt, so we catch the exception
			 * here, and only rethrow it if we don't recognise the error.
			 */
			
			try
			{
				$interest_groups = $this->_call_api('listInterestGroupings', array($r['id']));
			}
			catch (Exception $exception)
			{
				if ($exception->getCode() != '211')
				{
					error_log('Exception: ' .$exception->getMessage() .' (' . $exception->getCode() .')');
					throw $exception;
				}
				
				$interest_groups = array();
			}
			
			// Merge variables.
			$merge_vars = $this->_call_api('listMergeVars', array($r['id']));
			
			// Add the list.
			$lists[] = new MCS_Mailing_list(array(
				'interest_groups' 	=> $interest_groups,
				'id'				=> $r['id'],
				'merge_variables'	=> $merge_vars,
				'name'				=> $r['name']
			));
		}
		
		$this->_mailing_lists = $lists;
	}
	
	
	/**
	 * Loads the member fields from the database.
	 *
	 * @access	private
	 * @return	array
	 */
	private function _load_member_fields_from_db()
	{
		/**
		 * The default ExpressionEngine member fields are
		 * hard-coded. Not ideal, but we roll with it.
		 */
		
		$this->_ee->lang->loadfile('member');

		$member_fields = array(
			'location' => array(
				'id'		=> 'location',
				'label'		=> lang('mbr_location'),
				'options'	=> array(),
				'type'		=> 'text'
			),
			'screen_name' => array(
				'id'		=> 'screen_name',
				'label'		=> lang('mbr_screen_name'),
				'options'	=> array(),
				'type'		=> 'text'
			),
			'url' => array(
				'id'		=> 'url',
				'label'		=> lang('mbr_url'),
				'options'	=> array(),
				'type'		=> 'text'
			),
			'username' => array(
				'id'		=> 'username',
				'label'		=> lang('mbr_username'),
				'options'	=> array(),
				'type'		=> 'text'
			)
		);
		
		// Load the custom member fields.
		$db_member_fields = $this->_ee->db->select('m_field_id, m_field_label, m_field_type, m_field_list_items')->get('member_fields');
		
		if ($db_member_fields->num_rows() > 0)
		{
			foreach ($db_member_fields->result() AS $row)
			{
				$member_fields['m_field_id_' .$row->m_field_id] = array(
					'id'		=> 'm_field_id_' .$row->m_field_id,
					'label'		=> $row->m_field_label,
					'options'	=> $row->m_field_type == 'select' ? explode("\n", $row->m_field_list_items) : array(),
					'type'		=> $row->m_field_type == 'select' ? 'select' : 'text'
				);
			}
		}
		
		$this->_member_fields = $member_fields;
	}
	
	
	/**
	 * Loads the settings from the database.
	 *
	 * @access	private
	 * @return	void
	 */
	private function _load_settings_from_db()
	{
		$settings = new MCS_Settings();
		
		// Load the settings from the database.
		$db_settings = $this->_ee->db->select('settings')->get_where(
			'mailchimp_subscribe_settings',
			array('site_id' => $this->_site_id),
			1
		);
		
		// If we have saved settings, parse them.
		if ($db_settings->num_rows() > 0)
		{
			$this->_ee->load->helper('string');

			$site_settings = unserialize(strip_slashes($db_settings->row()->settings));
			
			$settings->populate_from_array($site_settings);
		}
		
		$this->_settings = $settings;
	}
	
	
	/**
	 * Subscribes a member to the active mailing lists, or updates
	 * a member's existing subscriptions.
	 *
	 * @access	private
	 * @param	string		$member_id		The member ID.
	 * @param	bool		$update			Are we updating existing subscriptions?
	 * @return	void
	 */
	private function _update_member_subscriptions($member_id = '', $update = FALSE)
	{
		// Check that we have a member ID.
		if ( ! $member_id)
		{
			throw new MCS_Data_exception('Unable to update member subscriptions (missing member ID).');
		}
		
		// Retrieve the member.
		$members = $this->get_members(array('member_id' => $member_id));
		
		if (count($members) !== 1)
		{
			throw new MCS_Data_exception('Error retrieving member ID ' .$member_id);
		}
		
		// Convenience.
		$member = $members[0];
		
		// Is the member banned?
		if (in_array($member['group_id'], array('2', '4')))
		{
			throw new MCS_Data_exception('Unable to update subscriptions for banned member ' .$member['screen_name'] .' (' .$member_id .')');
		}
		
		/**
		 * Process the mailing lists.
		 */
		
		$subscribe_to 		= array();
		$unsubscribe_from	= array();
		
		foreach ($this->_settings->mailing_lists AS $list)
		{
			/**
			 * If there is no trigger field, the member must be
			 * subscribed to the list.
			 */
			
			if ( ! $list->trigger_field)
			{
				$subscribe_to[] = $list;
				continue;
			}
			
			/**
			 * If there is a trigger field, we need to check whether
			 * the member has opted-in to this list.
			 */
			
			if (isset($member[$list->trigger_field]) && $member[$list->trigger_field] === $list->trigger_value)
			{
				$subscribe_to[] = $list;
			}
			else
			{
				$unsubscribe_from[] = $list;
			}
		}
		
		// Do we have an work to do?
		if (count($subscribe_to) == 0 && ($update == FALSE OR count($unsubscribe_from) == 0))
		{
			return;
		}
		
		// Let's get APIing. That's totally a word.
		foreach ($subscribe_to AS $list)
		{
			// Merge variables.
			$merge_vars = array();
			
			foreach ($list->merge_variables AS $tag => $val)
			{
				if ($val->tag && isset($member[$val->member_field_id]))
				{
					$merge_vars[$val->tag] = $member[$val->member_field_id];
				}
			}
			
			// Interest groups.
			$groupings = array();
			
			foreach ($list->interest_groups AS $id => $val)
			{
				if ($val->id && isset($member[$val->member_field_id]))
				{
					$groupings[$val->id] = array(
						'id'		=> $val->id,
						'groups'	=> str_replace(',', '\,', $member[$val->member_field_id])
					);
				}
			}
			
			if ($groupings)
			{
				$merge_vars['GROUPINGS'] = $groupings;
			}
			
			// Finally we can make the API call.
			$this->_call_api('listSubscribe', array(
				$list->id,
				$member['email'],
				$merge_vars,
				'html',				// Email format.
				FALSE,				// Double opt-in?
				(bool)$update		// Update existing subscription?
			));
		}
		
		// Process the unsubscriptions.
		if ($update)
		{
			foreach ($unsubscribe_from AS $list)
			{
				$this->_call_api('listUnsubscribe', array($list->id, $member['email']));
			}
		}
	}
	
}

/* End of file		: mailchimp_model.php */
/* File location	: /system/expressionengine/third_party/mailchimp_subscribe/models/mailchimp_model.php */