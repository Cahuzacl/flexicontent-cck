<?php
/**
 * @version 1.5 stable $Id: flexinotify.php 1806 2013-11-10 01:38:20Z ggppdk $
 * @package Joomla
 * @subpackage FLEXIcontent
 * @copyright (C) 2009 Emmanuel Danan - www.vistamedia.fr
 * @license GNU/GPL v2
 * 
 * FLEXIcontent is a derivative work of the excellent QuickFAQ component
 * @copyright (C) 2008 Christoph Lukes
 * see www.schlu.net for more information
 *
 * FLEXIcontent is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

// Check to ensure this file is included in Joomla!
defined( '_JEXEC' ) or die( 'Restricted access' );

jimport('cms.plugin.plugin');

/**
 * Flexicontent Notification Plugin
 *
 * @package		Joomla
 * @subpackage	FLEXIcontent
 * @since 		1.5.5
 */
class plgFlexicontentFlexinotify extends JPlugin
{

	/**
	 * Constructor
	 *
	 * For php4 compatability we must not use the __constructor as a constructor for plugins
	 * because func_get_args ( void ) returns a copy of all passed arguments NOT references.
	 * This causes problems with cross-referencing necessary for the observer design pattern.
	 *
	 * @param object $subject The object to observe
	 * @param object $params  The object that holds the plugin parameters
	 * @since 1.5
	 */
	function __construct( &$subject, $params )
	{
		parent::__construct( $subject, $params );

		JPlugin::loadLanguage( 'plg_flexicontent_flexinotify' );
	}


	/**
	 * This method is executed just before an item is stored
	 *
	 * Method is called by the model
	 *
	 * @param 	object		The item object.
	 * @param 	boolean		Indicates if item is new
	 */
	/*function onBeforeSaveItem( &$item, $isnew ) {
		$post = JRequest::get('post');
		//echo "<pre>"; $post; echo "</pre>"; exit;
		
		//...
		
		if ($somethingbad) {
			$app = &JFactory::getApplication();
			$app->enqueueMessage( 'Saving cancel due to error ...', 'notice' );
			return false;
		}
		
		return true;
	}*/
	
	
	/**
	 * This method is executed just after an item stored (including custom fields)
	 *
	 * Method is called by the model
	 *
	 * @param 	object		The item object.
	 * @param 	object		The complete $_POST data
	 */
	function onAfterSaveItem( &$item, &$post )
	{
		//****************************************
		// Checks to decide whether to send emails
		//****************************************
		
		// Get Plugin parameters and send notifications
		$plugin = JPluginHelper::getPlugin('flexicontent', 'flexinotify');
		$params = FLEXI_J16GE ? new JRegistry($plugin->params) : new JParameter($plugin->params);
		$debug_notifications = $params->get('debug_notifications', 0);
		if ( !$debug_notifications && empty($post['notify']) ) return;  // * Fast skip of uneeded code
		
		// *. Check if debug_notifications is for super admin only
		if ($debug_notifications==2) {
			$perms= FlexicontentHelperPerm::getPerm();
			if (!$perms->SuperAdmin) $debug_notifications = 0;
			if ( !$debug_notifications && empty($post['notify']) ) return;
			$params->set('debug_notifications', $debug_notifications);  // Set this for later usage ... not to recalculate
		}
		
		// a. Check if new document version was approved
		if ( $post['vstate']!=2 ) {
			if ($debug_notifications) JFactory::getApplication()->enqueueMessage("** Favourites Notification Plugin: &nbsp; Not sending notifications, because new document version did not become active (needs approval by a publisher/administrator)", 'message' );
			return;
		}
		
		// b. Check if current item has subscribers
		$subscribers = $this->_getSubscribers($item->id);
		if (count($subscribers) == 0) {
			if ($debug_notifications) JFactory::getApplication()->enqueueMessage("** Favourites Notification Plugin: &nbsp; Current content item has no subscribers", 'message' );
			return;
		}
		
		// c. Check if notification emails to subscribers were already sent during current session
		$session = JFactory::getSession();
		$subscribers_notified = $session->get('subscribers_notified', array(),'flexicontent');
		if ( !empty($subscribers_notified[$item->id]) ) {
			if ($debug_notifications) JFactory::getApplication()->enqueueMessage("** Favourites Notification Plugin: &nbsp; Notifications emails to subscribers were already sent during current session", 'message' );
			return;
		}
		
		// d. Check if notification flag was set
		if ( empty($post['notify']) ) {
			if ($debug_notifications) JFactory::getApplication()->enqueueMessage("** Favourites Notification Plugin: &nbsp; Current editor did not request to notify subscribers", 'message' );
			return;
		}
		
		// Send notifications (optinally these will be personalized)
		$this->sendNotifications($item, $subscribers, $params);
		
		// Set flag to avoid resending notifications
		$subscribers_notified[$item->id]  = 1;
		$session->set('subscribers_notified', $subscribers_notified, 'flexicontent');
	}
	
	
	/**
	 * This method is executed after item saving is complete (all data, e.g. including versioning metadata)
	 *
	 * Method is called by the model
	 *
	 * @param 	object		The item object.
	 * @param 	object		The complete $_POST data
	 */
	/*function onCompleteSaveItem( &$item, &$fields ) {
	}*/
	
	
	function _getSubscribers($itemid)
	{
		$db = JFactory::getDbo();
		
		$query	= 'SELECT u.* '
				.' FROM #__flexicontent_favourites AS f'
				.' LEFT JOIN #__users AS u'
				.' ON u.id = f.userid'
				.' WHERE f.itemid = ' . (int)$itemid . ' AND f.type = 0'
				.'  AND u.block=0 '
				;
		$db->setQuery($query);
		$users = $db->loadObjectList();
		
		return $users;
	}
	
	
	function sendNotifications($item, $subscribers, $params)
	{
		global $globalcats;
		$app = JFactory::getApplication();
		$config = JFactory::getConfig();
		$categories = & $globalcats;
		
		// Get the route helper
		require_once (JPATH_SITE.DS.'components'.DS.'com_flexicontent'.DS.'helpers'.DS.'route.php');
		
		// Import joomla mail helper class that contains the sendMail helper function
		jimport('joomla.mail.helper');
		$mailer = JFactory::getMailer();
		$mailer->Encoding = 'base64';
		
		// Parameters for 'message' language string
		//
		// 1: $subname	Name of the subscriber
		// 2: $itemid		ID of the item
		// 3: $title		Title of the item
		// 4: $maincat	Main category of the item
		// 5: $link			Link of the item
		// 6: $sitename	Website
		
		// Disable personalized messages if subscriber limit for personal messages is exceeded
		$send_personalized = $params->get('send_personalized', 1);
		if ($send_personalized)
		{
			$personalized_limit = $params->get('personalized_limit', 50);
			$personalized_limit = $personalized_limit <= 100 ? $personalized_limit : 100;
			$send_personalized  = count($subscribers) <= $personalized_limit ? true : false;
		}
		$include_fullname   = $params->get('include_fullname', 1);
		$debug_notifications= $params->get('debug_notifications', 0);
		
		
		// *********************************
		// Create variables need for subject
		// *********************************
		
		$subname 	= ($send_personalized && $include_fullname) ? '__SUBSCRIBER_NAME__' : JText::_('FLEXI_SUBSCRIBER');
		$itemid   = $item->id;
		$title    = $item->title;
		$maincat  = $categories[$item->catid]->title;

		// Create the non-SEF URL
		$site_languages = FLEXIUtilities::getLanguages();
		$sef_lang = $item->language != '*' && isset($site_languages->{$item->language}) ? $site_languages->{$item->language}->sef : '';
		$item_url =
			FlexicontentHelperRoute::getItemRoute($item->id.':'.$item->alias, $categories[$item->catid]->slug)
			. ($sef_lang ? '&lang=' . $sef_lang : '');

		// Create the SEF URL
		$item_url = $app->isAdmin()
			? flexicontent_html::getSefUrl($item_url)   // ..., $_xhtml= true, $_ssl=-1);
			: JRoute::_($item_url);  // ..., $_xhtml= true, $_ssl=-1);

		// Make URL absolute since this URL will be emailed
		$item_url = JUri::getInstance()->toString(array('scheme', 'host', 'port')) . $item_url;
		
		
		
		// ************************************************
		// Create parameters passed to mail helper function
		// ************************************************
		
		$sitename = $app->getCfg('sitename') . ' - ' . JUri::root();
		$sendermail	= $params->get('sendermail', $app->getCfg('mailfrom'));
		$sendermail	= JMailHelper::cleanAddress($sendermail);
		$sendername	= $params->get('sendername', $app->getCfg('sitename'));
		$subject	= $params->get('mailsubject', '') ? JMailHelper::cleanSubject($params->get('mailsubject')) : JText::_('FLEXI_SUBJECT_DEFAULT');
		$message 	= JText::sprintf('FLEXI_NOTIFICATION_MESSAGE', $subname, $itemid, $title, $maincat, '<a href="'.$item_url.'">'.$item_url.'</a>', $sitename);
		$message  = nl2br($message);
		
		
		// *************************************************
		// Send email notifications about item being updated
		// *************************************************
		
		// Personalized email per subscribers
		if ($send_personalized) {
			$count_sent = 0;
			$to_arr = array();
			foreach ($subscribers as $subscriber)
			{
				$to = JMailHelper::cleanAddress($subscriber->email);
				$to_arr[] = $to;
				$_message = $message;
				if ($include_fullname) $_message = str_replace('__SUBSCRIBER_NAME__', $subscriber->name, $_message);
				
				$from      = $sendermail;
				$fromname  = $sendername;
				$recipient = array($to);
				$html_mode=true; $cc=null; $bcc=null;
				$attachment=null; $replyto=null; $replytoname=null;
				
				$send_result = $mailer->sendMail( $from, $fromname, $recipient, $subject, $_message, $html_mode, $cc, $bcc, $attachment, $replyto, $replytoname );
				if ($send_result) $count_sent++;
			}
			$send_result = (boolean) $count_sent;
			if ($debug_notifications) JFactory::getApplication()->enqueueMessage("** Favourites Notification Plugin: &nbsp; Sent personalized message per subscriber", 'message' );
		}
		
		// Single same message for ALL subscribers
		else {
			$to_arr = array();
			$count = 0;
			foreach ($subscribers as $subscriber) {
				$to = JMailHelper::cleanAddress($subscriber->email);
				$to_arr[] = $to;
				$to_100_arr[ intval($count/100) ][] = $to;
				$count++;
			}
			
			$count_sent = 0;
			foreach ($to_100_arr as $to_100)
			{
				$from      = $sendermail;
				$fromname  = $sendername;
				$recipient = array($from);
				$html_mode=true; $cc=null; $bcc = $to_100;
				$attachment=null; $replyto=null; $replytoname=null;
				
				$send_result = $mailer->sendMail( $from, $fromname, $recipient, $subject, $message, $html_mode, $cc, $bcc, $attachment, $replyto, $replytoname );
				if ($send_result) $count_sent += count($to_100);
			}
			$send_result = (boolean) $count_sent;
			if ($debug_notifications) JFactory::getApplication()->enqueueMessage("** Favourites Notification Plugin: &nbsp; Sent same message to all subscribers", 'message' );
		}
		
		// Finally give some feedback to current editor, optionally including emails of receivers if debug is enabled
		$msg = $send_result ?
			JText::sprintf('FLEXI_NOTIFY_SUCCESS', $count_sent, count($subscribers)) :
			JText::sprintf('FLEXI_NOTIFY_FAILURE', count($subscribers))
		;
		$msg_receivers = !$debug_notifications ? "" : " <br/> Subscribers List: ". implode(", ", $to_arr);
		
		$app->enqueueMessage($msg.$msg_receivers, $send_result ? 'message' : 'warning' );
	}
}