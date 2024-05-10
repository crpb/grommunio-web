<?php

/**
 * Appointment Module.
 */
class AppointmentListModule extends ListModule {
	/**
	 * @var date start interval of view visible
	 */
	private $startdate;

	/**
	 * @var date end interval of view visible
	 */
	private $enddate;

	/**
	 * @var bool|string client timezone definition
	 */
	protected $tzdef;

	/**
	 * @var array|bool client timezone definition array
	 */
	protected $tzdefObj;

	/**
	 * @var mixed client timezone effective rule id
	 */
	protected $tzEffRuleIdx;

	/**
	 * Constructor.
	 *
	 * @param int   $id   unique id
	 * @param array $data list of all actions
	 */
	public function __construct($id, $data) {
		parent::__construct($id, $data);

		$this->properties = $GLOBALS["properties"]->getAppointmentListProperties();

		$this->startdate = false;
		$this->enddate = false;
		$this->tzdef = false;
		$this->tzdefObj = false;
	}

	/**
	 * Creates the notifiers for this module,
	 * and register them to the Bus.
	 */
	public function createNotifiers() {
		$entryid = $this->getEntryID();
		$GLOBALS["bus"]->registerNotifier('appointmentlistnotifier', $entryid);
	}

	/**
	 * Executes all the actions in the $data variable.
	 */
	public function execute() {
		foreach ($this->data as $actionType => $action) {
			if (isset($actionType)) {
				try {
					$store = $this->getActionStore($action);
					$entryid = $this->getActionEntryID($action);

					switch ($actionType) {
						case "list":
							$this->messageList($store, $entryid, $action, $actionType);
							break;

						case "search":
							// @FIXME add functionality to handle private items
							$this->search($store, $entryid, $action, $actionType);
							break;

						case "updatesearch":
							$this->updatesearch($store, $entryid, $action);
							break;

						case "stopsearch":
							$this->stopSearch($store, $entryid, $action);
							break;

						default:
							$this->handleUnknownActionType($actionType);
					}
				}
				catch (MAPIException $e) {
					if (isset($action['suppress_exception']) && $action['suppress_exception'] === true) {
						$e->setNotificationType('console');
					}
					$this->processException($e, $actionType);
				}
			}
		}
	}

	/**
	 * Function which retrieves a list of calendar items in a calendar folder.
	 *
	 * @param object $store      MAPI Message Store Object
	 * @param string $entryid    entryid of the folder
	 * @param array  $action     the action data, sent by the client
	 * @param string $actionType the action type, sent by the client
	 */
	public function messageList($store, $entryid, $action, $actionType) {
		if ($store && $entryid) {
			// initialize start and due date with false value so it will not take values from previous request
			$this->startdate = false;
			$this->enddate = false;

			if (isset($action["restriction"])) {
				if (isset($action["restriction"]["startdate"])) {
					$this->startdate = $action["restriction"]["startdate"];
				}

				if (isset($action["restriction"]["duedate"])) {
					$this->enddate = $action["restriction"]["duedate"];
				}
			}

			if (!empty($action["timezone_iana"])) {
				try {
					$this->tzdef = mapi_ianatz_to_tzdef($action['timezone_iana']);
				}
				catch (Exception $e) {
				}
			}

			if ($this->startdate && $this->enddate) {
				$data = [];

				if (is_array($entryid) && !empty($entryid)) {
					$data["item"] = [];
					for ($index = 0, $index2 = count($entryid); $index < $index2; ++$index) {
						$this->getDelegateFolderInfo($store[$index]);

						// Set the active store in properties class and get the props based on active store.
						// we need to do this because of multi server env where shared store belongs to the different server.
						// Here name space is different per server. e.g. There is user A and user B and both are belongs to
						// different server and user B is shared store of user A because of that user A has 'categories' => -2062020578
						// and user B 'categories' => -2062610402,
						$GLOBALS["properties"]->setActiveStore($store[$index]);
						$this->properties = $GLOBALS["properties"]->getAppointmentListProperties();

						$data["item"] = array_merge($data["item"], $this->getCalendarItems($store[$index], $entryid[$index], $this->startdate, $this->enddate));
					}
				}
				else {
					$this->getDelegateFolderInfo($store);
					$data["item"] = $this->getCalendarItems($store, $entryid, $this->startdate, $this->enddate);
				}

				$this->addActionData("list", $data);
				$GLOBALS["bus"]->addData($this->getResponseData());
			}
			else {
				// for list view in calendar as startdate and enddate is passed as false
				// this will set sorting and paging for items in listview.

				$this->getDelegateFolderInfo($store);

				/* This is an override for parent::messageList(), which ignores an array of entryids / stores.
				*	 The following block considers this possibly and merges the data of several folders / stores.
				*/

				$this->searchFolderList = false; // Set to indicate this is not the search result, but a normal folder content

				if ($store && $entryid) {
					// Restriction
					$this->parseRestriction($action);

					// Sort
					$this->parseSortOrder($action, null, true);

					$limit = false;
					if (isset($action['restriction']['limit'])) {
						$limit = $action['restriction']['limit'];
					}
					else {
						$limit = $GLOBALS['settings']->get('zarafa/v1/main/page_size', 50);
					}

					$isSearchFolder = isset($action['search_folder_entryid']);
					$entryid = $isSearchFolder ? hex2bin($action['search_folder_entryid']) : $entryid;

					if (!is_array($entryid) && !is_array($store)) {
						$entryid = [$entryid];
						$store = [$store];
					}

					// Get the table and merge the arrays
					$data = [];
					$items = [];
					for ($i = 0, $c = count($entryid); $i < $c; ++$i) {
						$newItems = $GLOBALS["operations"]->getTable($store[$i], $entryid[$i], $this->properties, $this->sort, $this->start, $limit, $this->restriction);
						$items = array_merge($items, $newItems['item']);
						if (isset($newItems['page']['totalrowcount']) && $newItems['page']['totalrowcount'] > $limit) {
							$data['page'] = $newItems['page'];
						}
					}

					// If the request come from search folder then no need to send folder information
					if (!$isSearchFolder) {
						$contentCount = 0;
						$contentUnread = 0;

						// For each folder
						for ($i = 0, $c = count($entryid); $i < $c; ++$i) {
							// Open folder
							$folder = mapi_msgstore_openentry($store[$i], $entryid[$i]);
							// Obtain some statistics from the folder contents
							$content = mapi_getprops($folder, [PR_CONTENT_COUNT, PR_CONTENT_UNREAD]);
							if (isset($content[PR_CONTENT_COUNT])) {
								$contentCount += $content[PR_CONTENT_COUNT];
							}

							if (isset($content[PR_CONTENT_UNREAD])) {
								$contentUnread += $content[PR_CONTENT_UNREAD];
							}
						}

						$data["folder"] = [];
						$data["folder"]["content_count"] = $contentCount;
						$data["folder"]["content_unread"] = $contentUnread;
					}

					$items = $this->filterPrivateItems(['item' => $items]);
					// unset will remove the value but will not regenerate array keys, so we need to
					// do it here
					$data["item"] = array_values($items["item"]);

					for ($i = 0, $c = count($entryid); $i < $c; ++$i) {
						// Allowing to hook in just before the data sent away to be sent to the client
						$GLOBALS['PluginManager']->triggerHook('server.module.listmodule.list.after', [
							'moduleObject' => &$this,
							'store' => $store[$i],
							'entryid' => $entryid[$i],
							'action' => $action,
							'data' => &$data,
						]);
					}

					$this->addActionData($actionType, $data);
					$GLOBALS["bus"]->addData($this->getResponseData());
				}
			}
		}
	}

	/**
	 * Function to return all Calendar items in a given timeframe. This
	 * function also takes recurring items into account.
	 *
	 * @param object $store   message store
	 * @param mixed  $entryid entryid of the folder
	 * @param mixed  $start   startdate of the interval
	 * @param mixed  $end     enddate of the interval
	 */
	public function getCalendarItems($store, $entryid, $start, $end) {
		$restriction =
			// OR
			//  - Either we want all appointments which fall within the given range
			//	- Or we want all recurring items which we manually check if an occurrence
			//	  exists which will fall inside the range
			[RES_OR,
				[
					// OR
					//	- Either we want all properties which fall inside the range (or overlap the range somewhere)
					//		(start < appointmentEnd && due > appointmentStart)
					//	- Or we want all zero-minute appointments which fall at the start of the restriction.
					// Note that this will effectively exclude any appointments which have an enddate on the restriction
					// start date, as those are not useful for us. Secondly, we exclude all zero-minute appointments
					// which fall on the end of the restriction as the restriction is <start, end].
					[RES_OR,
						[
							// AND
							//	- The AppointmentEnd must fall after the start of the range
							//	- The AppointmentStart must fall before the end of the range
							[RES_AND,
								[
									// start < appointmentEnd
									[RES_PROPERTY,
										[RELOP => RELOP_GT,
											ULPROPTAG => $this->properties["duedate"],
											VALUE => $start,
										],
									],
									// due > appointmentStart
									[RES_PROPERTY,
										[RELOP => RELOP_LT,
											ULPROPTAG => $this->properties["startdate"],
											VALUE => $end,
										],
									],
								],
							],
							// AND
							//	- The AppointmentStart equals the start of the range
							//	- The AppointmentEnd equals the start of the range
							// In other words the zero-minute appointments on the start of the range
							[RES_AND,
								[
									// appointmentStart == start
									[RES_PROPERTY,
										[RELOP => RELOP_EQ,
											ULPROPTAG => $this->properties["startdate"],
											VALUE => $start,
										],
									],
									// appointmentEnd == start
									[RES_PROPERTY,
										[RELOP => RELOP_EQ,
											ULPROPTAG => $this->properties["duedate"],
											VALUE => $start,
										],
									],
								],
							],
						],
					],
					// OR
					// (item[isRecurring] == true)
					// Add one day to the start and the end of the periods to avoid
					// timezone offset related differences between start/clipstart
					// and end/clipend.
					[RES_AND,
						[
							[RES_PROPERTY,
								[RELOP => RELOP_EQ,
									ULPROPTAG => $this->properties["recurring"],
									VALUE => true,
								],
							],
							[RES_AND,
								[
									[RES_PROPERTY,
										[RELOP => RELOP_GT,
											ULPROPTAG => $this->properties["enddate_recurring"],
											VALUE => (int) $start - 86400,
										],
									],
									[RES_PROPERTY,
										[RELOP => RELOP_LT,
											ULPROPTAG => $this->properties["startdate_recurring"],
											VALUE => (int) $end + 86400,
										],
									],
								],
							],
						],
					],
				],
			]; // global OR

		try {
			$folder = mapi_msgstore_openentry($store, $entryid);
			$table = mapi_folder_getcontentstable($folder, MAPI_DEFERRED_ERRORS);
			$calendaritems = mapi_table_queryallrows($table, $this->properties, $restriction);

			return $this->processItems($calendaritems, $store, $entryid, $start, $end);
		}
		catch (Exception $e) {
			// MAPI_E_NOT_FOUND means missing permissions, try to get items via freebusy
			if ($e->getCode() == MAPI_E_NOT_FOUND) {
				return $this->getFreebusyItems($store, $entryid, $start, $end);
			}
		}

		return [];
	}

	/**
	 * Process calendar items to prepare them for being sent back to the client.
	 *
	 * @param array  $calendaritems array of appointments retrieved from the mapi tablwe
	 * @param object $store         message store
	 * @param mixed  $entryid
	 * @param mixed  $start         startdate of the interval
	 * @param mixed  $end           enddate of the interval
	 *
	 * @return array $items processed items
	 */
	public function processItems($calendaritems, $store, $entryid, $start, $end) {
		$items = [];
		$openedMessages = [];
		$proptags = $GLOBALS["properties"]->getRecurrenceProperties();

		foreach ($calendaritems as $calendaritem) {
			$item = null;
			// Fix for all-day events which have a different timezone than the user's browser
			if (isset($calendaritem[$this->properties["recurring"]]) && $calendaritem[$this->properties["recurring"]]) {
				$recurrence = new Recurrence($store, $calendaritem, $proptags);
				$recuritems = $recurrence->getItems($start, $end);

				foreach ($recuritems as $recuritem) {
					$item = Conversion::mapMAPI2XML($this->properties, $recuritem);

					// Single occurrences are never recurring
					$item['props']['recurring'] = false;

					if (isset($recuritem["exception"])) {
						$item["props"]["exception"] = true;
					}

					if (isset($recuritem["basedate"])) {
						$item["props"]["basedate"] = $recuritem["basedate"];
					}

					if (isset($recuritem["exception"])) {
						// Add categories if they are set on the exception
						// We will create a new Recurrence object with the opened message,
						// so we can open the attachments. The attachments for this exception
						// contains the categories property (if changed)
						$msgEntryid = bin2hex($calendaritem[$this->properties["entryid"]]);
						if (!isset($openedMessages[$msgEntryid])) {
							// Open the message and add it to the openedMessages property
							$message = mapi_msgstore_openentry($store, $calendaritem[$this->properties["entryid"]]);
							$openedMessages[$msgEntryid] = $message;
						}
						else {
							// This message was already opened
							$message = $openedMessages[$msgEntryid];
						}
						// Now create a Recurrence object with the mapi message (instead of the message props)
						// so we can open the attachments
						$recurrence = new Recurrence($store, $message, $proptags);
						$exceptionatt = $recurrence->getExceptionAttachment($recuritem["basedate"]);
						if ($exceptionatt) {
							// Existing exception (open existing item, which includes basedate)
							$exception = mapi_attach_openobj($exceptionatt, 0);
							$exceptionProps = $GLOBALS['operations']->getMessageProps($store, $exception, ['categories' => $this->properties["categories"]]);

							if (isset($exceptionProps['props']['categories'])) {
								$item["props"]["categories"] = $exceptionProps['props']['categories'];
							}
						}
					}

					$item = $this->processPrivateItem($item);

					// only add it in response if its not removed by above function
					if (!empty($item)) {
						if (empty($item["props"]["commonstart"]) && isset($item["props"]["startdate"])) {
							$item["props"]["commonstart"] = $item["props"]["startdate"];
						}
						if (empty($item["props"]["commonend"]) && isset($item["props"]["duedate"])) {
							$item["props"]["commonend"] = $item["props"]["duedate"];
						}
						if (isset($item["props"]["alldayevent"]) && $item["props"]["alldayevent"]) {
							$this->processAllDayItem($store, $item, $openedMessages);
						}
						array_push($items, $item);
					}
				}
			}
			else {
				$item = Conversion::mapMAPI2XML($this->properties, $calendaritem);

				$item = $this->processPrivateItem($item);

				// only add it in response if its not removed by above function
				if (!empty($item)) {
					if (empty($item["props"]["commonstart"]) && isset($item["props"]["startdate"])) {
						$item["props"]["commonstart"] = $item["props"]["startdate"];
					}
					if (empty($item["props"]["commonend"]) && isset($item["props"]["duedate"])) {
						$item["props"]["commonend"] = $item["props"]["duedate"];
					}
					if (isset($item["props"]["alldayevent"]) && $item["props"]["alldayevent"]) {
						$this->processAllDayItem($store, $item, $openedMessages);
					}
					array_push($items, $item);
				}
			}
		}

		usort($items, ["AppointmentListModule", "compareCalendarItems"]);

		return $items;
	}

	/**
	 * Function will be used to process private items in a list response, modules can
	 * can decide what to do with the private items, remove the entire row or just
	 * hide the data. This function will only hide the data of the private appointments.
	 *
	 * @param object $item item properties
	 *
	 * @return object item properties after processing private items
	 */
	public function processPrivateItem($item) {
		if ($this->startdate && $this->enddate) {
			if ($this->checkPrivateItem($item)) {
				$item['props']['subject'] = _('Private Appointment');
				$item['props']['normalized_subject'] = _('Private Appointment');
				$item['props']['location'] = '';
				$item['props']['reminder'] = 0;
				$item['props']['access'] = 0;
				$item['props']['sent_representing_name'] = '';
				$item['props']['sender_name'] = '';

				return $item;
			}

			return $item;
		}

		// if we are in list view then we need to follow normal procedure of other listviews
		return parent::processPrivateItem($item);
	}

	/**
	 * Function will sort items for the month view
	 * small startdate on top.
	 *
	 * @param mixed $a
	 * @param mixed $b
	 */
	public static function compareCalendarItems($a, $b) {
		$start_a = $a["props"]["startdate"];
		$start_b = $b["props"]["startdate"];

		if ($start_a == $start_b) {
			return 0;
		}

		return ($start_a < $start_b) ? -1 : 1;
	}

	/**
	 * Processes an all-day item and calculates the correct starttime if necessary.
	 *
	 * @param object $store
	 * @param array  $calendaritem
	 * @param array  $openedMessages
	 */
	private function processAllDayItem($store, &$calendaritem, &$openedMessages) {
		if (!isset($calendaritem['props']['tzdefstart'])) {
			return;
		}
		$tzdefstart = hex2bin($calendaritem['props']['tzdefstart']);

		// queryrows only returns 510 chars max, so if tzdef is longer than that
		// it was probably silently truncated. In such case we need to open
		// the message and read the prop value as stream.
		if (strlen($calendaritem['props']['tzdefstart']) > 500) {
			if (!isset($openedMessages[$calendaritem['entryid']])) {
				// Open the message and add it to the openedMessages property
				$openedMessages[$calendaritem['entryid']] = mapi_msgstore_openentry($store, hex2bin($calendaritem['entryid']));
			}
			$tzdefstart = streamProperty($openedMessages[$calendaritem['entryid']], $this->properties['tzdefstart']);
		}

		// Compare the timezone definitions of the client and the appointment.
		// Further processing is only required if they don't match.
		if (!$GLOBALS['entryid']->compareEntryIds($this->tzdef, $tzdefstart)) {
			if ($this->tzdefObj === false) {
				$this->tzdefObj = $GLOBALS['entryid']->createTimezoneDefinitionObject($this->tzdef);
			}
			$this->tzEffRuleIdx = getEffectiveTzreg($this->tzdefObj['rules']);

			$appTzDefStart = $GLOBALS['entryid']->createTimezoneDefinitionObject($tzdefstart);
			// Find TZRULE_FLAG_EFFECTIVE_TZREG rule for the appointment's timezone
			$appTzEffRuleIdx = getEffectiveTzreg($appTzDefStart['rules']);

			if (!is_null($this->tzEffRuleIdx) && !is_null($appTzEffRuleIdx)) {
				// first apply the bias of the appointment timezone and the bias of the browser
				$localStart = $calendaritem['props']['startdate'] - $appTzDefStart['rules'][$appTzEffRuleIdx]['bias'] * 60 + $this->tzdefObj['rules'][$this->tzEffRuleIdx]['bias'] * 60;
				if (isDst($appTzDefStart['rules'][$appTzEffRuleIdx], $calendaritem['props']['startdate'])) {
					$localStart -= $appTzDefStart['rules'][$appTzEffRuleIdx]['dstbias'] * 60;
				}
				if (isDst($this->tzdefObj['rules'][$this->tzEffRuleIdx], $calendaritem['props']['startdate'])) {
					$localStart += $this->tzdefObj['rules'][$this->tzEffRuleIdx]['dstbias'] * 60;
				}
				$duration = $calendaritem['props']['duedate'] - $calendaritem['props']['startdate'];
				$calendaritem['props']['startdate'] = $calendaritem['props']['commonstart'] = $localStart;
				$calendaritem['props']['duedate'] = $calendaritem['props']['commonend'] = $localStart + $duration;
			}
		}
	}

	/**
	 * Gets items using freebusy entry point.
	 *
	 * @param object $store         message store
	 * @param mixed  $folderEntryid entryid of the folder
	 * @param mixed  $start         startdate of the interval
	 * @param mixed  $end           enddate of the interval
	 */
	public function getFreebusyItems($store, $folderEntryid, $start, $end) {
		$items = [];
		$storeProps = mapi_getprops($store, [PR_ENTRYID, PR_MAILBOX_OWNER_ENTRYID]);
		$folderEntryid = bin2hex($folderEntryid);
		$storeEntryid = bin2hex($storeProps[PR_ENTRYID]);
		// if start was not set, get items one month back
		if ($start === false) {
			$start = time() - 2592000;
		}
		// if end was not set, get items 3 months ahead
		if ($end === false) {
			$end = time() + 7776000;
		}
		$fbdata = mapi_getuserfreebusy($GLOBALS['mapisession']->getSession(), $storeProps[PR_MAILBOX_OWNER_ENTRYID], $start, $end);
		if (!empty($fbdata['fbevents'])) {
			foreach ($fbdata['fbevents'] as $fbEvent) {
				// check if the event is in start - end range
				if ($fbEvent['end'] < $start || $fbEvent['start'] > $end) {
					continue;
				}
				$items[] = [
					// entryid is required, generate a fake one if a real is not available
					'entryid' => isset($fbEvent['id']) ? bin2hex($fbEvent['id']) : bin2hex(random_bytes(16)),
					'parent_entryid' => $folderEntryid,
					'store_entryid' => $storeEntryid,
					'props' => [
						'access' => 0,
						'subject' => $fbEvent['subject'] ?? _('Busy'),
						'normalized_subject' => $fbEvent['subject'] ?? _('Busy'),
						'location' => $fbEvent['location'] ?? '',
						'startdate' => $fbEvent['start'],
						'duedate' => $fbEvent['end'],
						'commonstart' => $fbEvent['start'],
						'commonend' => $fbEvent['end'],
						'message_class' => 'IPM.Appointment',
						'object_type' => MAPI_MESSAGE,
						'icon_index' => 1024,
						'display_to' => '',
						'display_cc' => '',
						'display_bcc' => '',
						'importance' => 1,
						'sensitivity' => 0,
						'message_size' => 0,
						'hasattach' => false,
						'sent_representing_entryid' => '',
						'sent_representing_name' => '',
						'sent_representing_address_type' => '',
						'sent_representing_email_address' => '',
						'sent_representing_search_key' => '',
						'sender_email_address' => '',
						'sender_name' => '',
						'sender_address_type' => '',
						'sender_entryid' => '',
						'sender_search_key' => '',
						'recurring' => false,
						'recurring_data' => '',
						'recurring_pattern' => '',
						'label' => 0,
						'meeting' => $fbEvent['meeting'],
						'reminder' => 0,
						'reminder_minutes' => 0,
					],
				];
			}
		}

		return $items;
	}
}
