<?php

//
// AWS Simple Notification Service Web Hook
// =============================================================================
//
// Post notifications from an AWS SNS topic.
//
// Author: [Stephen Ash](stephenash@gmail.com)
//
// -----------------------------------------------------------------------------
//

class aws_sns extends SlackServicePlugin {
	public $name = "AWS Simple Notification Service";
	public $desc = "Post notifications from an AWS SNS topic.";

	public function getLabel() {
		return "Post SNS notifications to {$this->icfg['channel_name']} as {$this->icfg['botname']}";
	}

	public function onInit() {
		$channels = $this->getChannelsList();
		foreach ($channels as $k => $v){
			if ($v == '#general'){
				$this->icfg['channel'] = $k;
				$this->icfg['channel_name'] = $v;
			}
		}

		$this->icfg['topics'] = '';
		$this->icfg['botname'] = 'AWS Simple Notification Service';
		$this->icfg['icon_emoji'] = '';
		$this->icfg['icon_url'] = trim($GLOBALS['cfg']['root_url'], '/') . '/plugins/aws_sns/icon_48.png';
	}

	public function onView() {
    	return $this->smarty->fetch('view.tpl.html');
	}

	public function onEdit(){
		$channels = $this->getChannelsList();

		if ($_GET['save']){
			$this->icfg['channel'] = $_POST['channel'];
			$this->icfg['channel_name'] = $channels[$_POST['channel']];
			$this->icfg['topics'] = array_map("trim", preg_split("/\r\n|\n|\r/", $_POST['topics'], -1, PREG_SPLIT_NO_EMPTY));

			$this->icfg['botname'] = $_POST['botname'];
			$this->icfg['icon_emoji'] = $_POST['icon_emoji'];
			$this->icfg['icon_url'] = $_POST['icon_url'];
			$this->saveConfig();

			header("location: {$this->getViewUrl()}&saved=1");
			exit;
		}

		$this->smarty->assign('channels', $channels);
		$this->smarty->assign('root_url', $cfg['root_url']);

		return $this->smarty->fetch('edit.tpl.html');
	}

	public function onHook($req) {
		$r = NULL;

		$payload = json_decode($req['post_body'], true);
		if (!$payload || !is_array($payload)) {
			return array(
				'ok' => false,
				'error' => "No payload received from SNS",
			);
		}

		// Verify that the SNS topic is one that matches what the hook instance is setup to accept from
		$msg_topic = $req['headers']['X-Amz-Sns-Topic-Arn'];
		$found_match = false;
		foreach ($this->icfg['topics'] as $topic_pattern) {
			if (preg_match("/$topic_pattern/", $msg_topic)) {
				$found_match = true;
				break;
			}
		}

		if ($found_match == false) {
			return array(
				'ok' => false,
				'error' => "Received notification from topic that was not defined in the hook instance's topic list: '$msg_topic'",
			);
		}

		$msg_type = $req['headers']['X-Amz-Sns-Message-Type'];
		switch ($msg_type) {
			case "SubscriptionConfirmation":
				$r = $this->sns_SubscriptionConfirmation($payload);
				break;
			case "Notification":
				$r = $this->sns_Notification($payload);
				break;
			case "UnsubscribeConfirmation":
				$r = $this->sns_UnsubscribeConfirmation($payload);
				break;
			default:
				return array(
					'ok' => false,
					'error' => "Unknown X-Amz-Sns-Message-Type header received: '$msg_type'",
				);
		}
		
		return $r;
	}

	private function sns_SubscriptionConfirmation($payload) {
		$subscription_url = $payload['SubscribeURL'];
		$subscription_topic = $payload['TopicArn'];
		$resp = SlackHTTP::get($subscription_url);

		if (!$resp || !is_array($resp) || $resp['ok'] == false) {
			return array(
				'ok'	=> false,
				'error' => "Error subscribing to SNS topic",
				'resp'  => $resp,
			);
		}

		return array(
				'ok'     => true,
				'status' => "Subscribed to '$subscription_topic'",
			);
	}

	private function sns_Notification($payload) {
		$sns_topic = $payload['TopicArn'];
		$sns_short_topic = substr($sns_topic, strrpos($sns_topic, ':') + 1);
		$sns_subject = $payload['Subject'];
		$sns_message = $payload['Message'];
		$sns_time = $payload['Timestamp'];

		$chat_message = "Notification from " . $this->escapeText($sns_topic);

		// Subject is an optional field for SNS messages.
		$subject = array();
		if ($sns_subject != NULL && $sns_subject != "") {
			$subject = array(
					'title' => "Subject",
					'value' => $this->escapeText($sns_subject),
					'short' => false,
				);
		}

		$extras['attachments'] = array(
				array(
					'fields' => array(
						$subject,
						array(
							'title' => "Message",
							'value' => $this->escapeText($sns_message),
							'short' => false,
						),
						array(
							'title' => "Time",
							'value' => $this->escapeText($sns_time),
							'short' => true,
						),
					),
				),
			);

		$this->sendMessage($chat_message, $extras);

		return array(
				'ok' => true,
				'status' => $short_topic,
			);		
	}

	private function sns_UnsubscribeConfirmation($payload) {
		$subscription_topic = $payload['TopicArn'];
		return array(
				'ok' => true,
				'status' => "Ubsubscribed from '$subscription_topic'",
			);
	}

	private function sendMessage($text, $extras = array()) {
		$defaults = array(
			'channel'  => $this->icfg['channel'],
			'username' => $this->icfg['botname'],
			'icon_emoji' => $this->icfg['icon_emoji'],
			'icon_url' => $this->icfg['icon_url'],
		);

		$params = array_merge($defaults, $extras);

		$ret = $this->postToChannel($text, $params);

		return array(
				'ok' => true,
				'status' => 'Sent a message',
			);
	}
}

?>