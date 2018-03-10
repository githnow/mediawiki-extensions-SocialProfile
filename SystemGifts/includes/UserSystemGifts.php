<?php

use MediaWiki\Logger\LoggerFactory;

/**
 * Class for managing awards (a.k.a system gifts)
 */
class UserSystemGifts {

	public $user_id;		# Text form (spaces not underscores) of the main part
	public $user_name;		# Text form (spaces not underscores) of the main part

	/**
	 * Constructor
	 */
	public function __construct( $username ) {
		$title1 = Title::newFromDBkey( $username );
		$this->user_name = $title1->getText();
		$this->user_id = User::idFromName( $this->user_name );
	}

	/**
	 * Gives out a system gift with the ID of $gift_id, purges memcached and
	 * optionally sends out e-mail to the user about their new system gift.
	 *
	 * @param int $gift_id ID number of the system gift
	 * @param bool $email True to send out notification e-mail to users,
	 * otherwise false
	 */
	public function sendSystemGift( $gift_id, $email = true ) {
		global $wgMemc;

		if ( $this->doesUserHaveGift( $this->user_id, $gift_id ) ) {
			return '';
		}

		$dbw = wfGetDB( DB_MASTER );
		$dbw->insert(
			'user_system_gift',
			array(
				'sg_gift_id' => $gift_id,
				'sg_user_id' => $this->user_id,
				'sg_user_name' => $this->user_name,
				'sg_status' => 1,
				'sg_date' => date( 'Y-m-d H:i:s' ),
			),
			__METHOD__
		);
		$sg_gift_id = $dbw->insertId();
		self::incGiftGivenCount( $gift_id );

		// Add to new gift count cache for receiving user
		$this->incNewSystemGiftCount( $this->user_id );

		if ( $email && !empty( $sg_gift_id ) ) {
			$this->sendGiftNotificationEmail( $this->user_id, $gift_id );
		}

		if ( ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ) {
			$userFrom = User::newFromId( $this->user_id );

			$giftObj = SystemGifts::getGift( $gift_id );
			EchoEvent::create( array(
				'type' => 'social-award-rec',
				'agent' => $userFrom,
				'extra' => array(
					'notifyAgent' => true,
					'target' => $this->user_id,
					'mastergiftid' => $gift_id,
					'giftid' => $sg_gift_id,
					'giftname' => $giftObj['gift_name']
				)
			) );
		}

		$wgMemc->delete( $wgMemc->makeKey( 'user', 'profile', 'system_gifts', $this->user_id ) );

		return $sg_gift_id;
	}

	/**
	 * Sends notification e-mail to the user with the ID $user_id_to whenever
	 * they get a new system gift (award) if their e-mail address is confirmed
	 * and they have opted in to these notifications on their social
	 * preferences.
	 *
	 * @param int $user_id_to User ID of the recipient
	 * @param int $gift_id System gift ID number
	 */
	public function sendGiftNotificationEmail( $user_id_to, $gift_id ) {
		$gift = SystemGifts::getGift( $gift_id );
		$user = User::newFromId( $user_id_to );
		$user->loadFromDatabase();

		$wantsEmail = ExtensionRegistry::getInstance()->isLoaded( 'Echo' ) ? $user->getBoolOption( 'echo-subscriptions-email-social-award' ) : $user->getIntOption( 'notifygift', 1 );
		if ( $user->isEmailConfirmed() && $wantsEmail ) {
			$gifts_link = SpecialPage::getTitleFor( 'ViewSystemGifts' );
			$update_profile_link = SpecialPage::getTitleFor( 'UpdateProfile' );
			$subject = wfMessage( 'system_gift_received_subject',
				$gift['gift_name']
			)->text();
			if ( trim( $user->getRealName() ) ) {
				$name = $user->getRealName();
			} else {
				$name = $user->getName();
			}
			$body = array(
				'html' => wfMessage( 'system_gift_received_body_html',
					$name,
					$gift['gift_name'],
					$gift['gift_description']
				)->parse(),
				'text' => wfMessage( 'system_gift_received_body',
					$name,
					$gift['gift_name'],
					$gift['gift_description'],
					$gifts_link->getFullURL(),
					$update_profile_link->getFullURL()
				)->text()
			);

			$user->sendMail( $subject, $body );
		}
	}

	/**
	 * Checks if the user with the ID $user_id has the system gift with the ID
	 * $gift_id by querying the user_system_gift table.
	 *
	 * @param int $user_id User ID
	 * @param int $gift_id System gift ID
	 * @return bool True if the user has the gift, otherwise false
	 */
	public function doesUserHaveGift( $user_id, $gift_id ) {
		$dbr = wfGetDB( DB_REPLICA );
		$s = $dbr->selectRow(
			'user_system_gift',
			array( 'sg_status' ),
			array( 'sg_user_id' => $user_id, 'sg_gift_id' => $gift_id ),
			__METHOD__
		);
		if ( $s !== false ) {
			return true;
		}
		return false;
	}

	static function clearUserGiftStatus( $id ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update( 'user_system_gift',
			/* SET */array( 'sg_status' => 0 ),
			/* WHERE */array( 'sg_id' => $id ),
			__METHOD__
		);
	}

	/**
	 * Checks if the user whose user ID is $user_id owns the system gift with
	 * the ID = $sg_id.
	 *
	 * @param int $user_id User ID
	 * @param int $sg_id ID Number of the system gift whose ownership
	 * we're trying to figure out here.
	 * @return bool True if the specified user owns the system gift,
	 * otherwise false
	 */
	public function doesUserOwnGift( $user_id, $sg_id ) {
		$dbr = wfGetDB( DB_REPLICA );
		$s = $dbr->selectRow(
			'user_system_gift',
			array( 'sg_user_id' ),
			array( 'sg_id' => $sg_id ),
			__METHOD__
		);
		if ( $s !== false ) {
			if ( $user_id == $s->ug_user_id_to ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Deletes the system gift that has the ID $ug_id.
	 *
	 * @param int $ug_id Gift ID of the system gift
	 * that we're about to delete.
	 */
	static function deleteGift( $ug_id ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->delete(
			'user_system_gift',
			array( 'sg_id' => $ug_id ),
			__METHOD__
		);
	}

	/**
	 * Get information about the system gift with the ID $id from the database.
	 * This info includes, but is not limited to, the gift ID, its description,
	 * name, status and so on.
	 *
	 * @param int $id System gift ID number
	 * @return array Array containing information about the system gift
	 */
	static function getUserGift( $id ) {
		$dbr = wfGetDB( DB_REPLICA );
		$res = $dbr->select(
			array( 'user_system_gift', 'system_gift' ),
			array(
				'sg_id', 'sg_user_id', 'sg_user_name', 'gift_id', 'sg_date',
				'gift_name', 'gift_description', 'gift_given_count', 'sg_status'
			),
			array( "sg_id = {$id}" ),
			__METHOD__,
			array(
				'LIMIT' => 1,
				'OFFSET' => 0
			),
			array( 'system_gift' => array( 'INNER JOIN', 'sg_gift_id = gift_id' ) )
		);
		$row = $dbr->fetchObject( $res );
		$gift = array();
		if ( $row ) {
			$gift['id'] = $row->sg_id;
			$gift['user_id'] = $row->sg_user_id;
			$gift['user_name'] = $row->sg_user_name;
			$gift['gift_count'] = $row->gift_given_count;
			$gift['timestamp'] = $row->sg_date;
			$gift['gift_id'] = $row->gift_id;
			$gift['name'] = $row->gift_name;
			$gift['description'] = $row->gift_description;
			$gift['status'] = $row->sg_status;
		}

		return $gift;
	}

	/**
	 * Increase the amount of new system gifts for the user with ID = $user_id.
	 *
	 * @param int $user_id User ID for the user
	 * whose gift count we're going to increase.
	 */
	public function incNewSystemGiftCount( $user_id ) {
		global $wgMemc;
		$key = $wgMemc->makeKey( 'system_gifts', 'new_count', $user_id );
		$wgMemc->incr( $key );
	}

	/**
	 * Decrease the amount of new system gifts for the user with ID = $user_id.
	 *
	 * @param int $user_id User ID for the user
	 * whose gift count we're going to decrease.
	 */
	public function decNewSystemGiftCount( $user_id ) {
		global $wgMemc;
		$key = $wgMemc->makeKey( 'system_gifts', 'new_count', $user_id );
		$wgMemc->decr( $key );
	}

	/**
	 * Get the amount of new system gifts for the user with ID = $user_id
	 * from memcached. If successful, returns the amount of new system gifts.
	 *
	 * @param int $user_id User ID for the user
	 * whose system gifts we're going to fetch.
	 * @return int Amount of new system gifts
	 */
	static function getNewSystemGiftCountCache( $user_id ) {
		global $wgMemc;
		$key = $wgMemc->makeKey( 'system_gifts', 'new_count', $user_id );
		$data = $wgMemc->get( $key );
		if ( $data != '' ) {
			$logger = LoggerFactory::getInstance( 'SocialProfile' );
			$logger->debug( "Got new award count of {data} for {user_id} from cache\n", [
				'data' => $data,
				'user_id' => $user_id
			] );

			return $data;
		}
	}

	/**
	 * Get the amount of new system gifts for the user with ID = $user_id.
	 * First tries cache (memcached) and if that succeeds, returns the cached
	 * data. If that fails, the count is fetched from the database.
	 * UserWelcome.php calls this function.
	 *
	 * @param $user_id int User ID for the user
	 * whose system gifts we're going to fetch.
	 * @return int Amount of new gifts
	 */
	static function getNewSystemGiftCount( $user_id ) {
		global $wgMemc;
		$data = self::getNewSystemGiftCountCache( $user_id );

		if ( $data != '' ) {
			$count = $data;
		} else {
			$count = self::getNewSystemGiftCountDB( $user_id );
		}
		return $count;
	}

	/**
	 * Get the amount of new system gifts for the user with ID = $user_id from
	 * the database and stores it in memcached.
	 *
	 * @param $user_id int User ID for the user
	 * whose system gifts we're going to fetch.
	 * @return int Amount of new system gifts
	 */
	static function getNewSystemGiftCountDB( $user_id ) {
		$logger = LoggerFactory::getInstance( 'SocialProfile' );
		$logger->debug( "Got new award count for id {user_id} from DB\n", [
			'user_id' => $user_id
		] );

		global $wgMemc;
		$key = $wgMemc->makeKey( 'system_gifts', 'new_count', $user_id );
		$dbr = wfGetDB( DB_REPLICA );
		$new_gift_count = 0;
		$s = $dbr->selectRow(
			'user_system_gift',
			array( 'COUNT(*) AS count' ),
			array( 'sg_user_id' => $user_id, 'sg_status' => 1 ),
			__METHOD__
		);
		if ( $s !== false ) {
			$new_gift_count = $s->count;
		}

		$wgMemc->set( $key, $new_gift_count );

		return $new_gift_count;
	}

	/**
	 * Get the list of this user's system gifts.
	 *
	 * @param $type Unused
	 * @param int $limit LIMIT for the SQL query
	 * @param int $page If greater than 0, used to build
	 * the OFFSET for the SQL query
	 * @return array Array of system gift information
	 */
	public function getUserGiftList( $type, $limit = 0, $page = 0 ) {
		$dbr = wfGetDB( DB_REPLICA );

		$limitvalue = 0;
		if ( $limit > 0 && $page ) {
			$limitvalue = $page * $limit - ( $limit );
		}

		$res = $dbr->select(
			array( 'user_system_gift', 'system_gift' ),
			array(
				'sg_id', 'sg_user_id', 'sg_user_name', 'sg_gift_id', 'sg_date',
				'sg_status', 'gift_name', 'gift_description',
				'gift_given_count'
			),
			array( "sg_user_id = {$this->user_id}" ),
			__METHOD__,
			array(
				'ORDER BY' => 'sg_id DESC',
				'LIMIT' => $limit,
				'OFFSET' => $limitvalue
			),
			array( 'system_gift' => array( 'INNER JOIN', 'sg_gift_id = gift_id' ) )
		);

		$requests = array();
		foreach ( $res as $row ) {
			$requests[] = array(
				'id' => $row->sg_id,
				'gift_id' => $row->sg_gift_id,
				'timestamp' => $row->sg_date,
				'status' => $row->sg_status,
				'user_id' => $row->sg_user_id,
				'user_name' => $row->sg_user_name,
				'gift_name' => $row->gift_name,
				'gift_description' => $row->gift_description,
				'gift_given_count' => $row->gift_given_count,
				'unix_timestamp' => wfTimestamp( TS_UNIX, $row->sg_date )
			);
		}
		return $requests;
	}

	/**
	 * Update the counter that tracks how many times a system gift has been
	 * given out.
	 *
	 * @param int $giftId ID number of the system gift that we're tracking
	 */
	public static function incGiftGivenCount( $giftId ) {
		$dbw = wfGetDB( DB_MASTER );
		$dbw->update(
			'system_gift',
			array( 'gift_given_count = gift_given_count + 1' ),
			array( 'gift_id' => $giftId ),
			__METHOD__
		);
	}

	/**
	 * Get the amount of system gifts for the specified user.
	 *
	 * @param mixed $user_name User name for the user whose gift
	 * count we're looking up; this is used to find out their UID.
	 * @return int Gift count for the specified user
	 */
	static function getGiftCountByUsername( $user_name ) {
		$dbr = wfGetDB( DB_REPLICA );
		$user_id = User::idFromName( $user_name );
		$res = $dbr->select(
			'user_system_gift',
			array( 'COUNT(*) AS count' ),
			array( "sg_user_id = {$user_id}" ),
			__METHOD__,
			array( 'LIMIT' => 1, 'OFFSET' => 0 )
		);
		$row = $dbr->fetchObject( $res );
		$gift_count = 0;
		if ( $row ) {
			$gift_count = $row->count;
		}
		return $gift_count;
	}
}