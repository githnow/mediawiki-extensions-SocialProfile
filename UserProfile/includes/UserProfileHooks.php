<?php

class UserProfileHooks {

	/**
	 * Registers the following custom tags with the Parser:
	 * - <randomuserswithavatars>
	 * - <newusers>
	 *
	 * @param Parser $parser
	 */
	public static function onParserFirstCallInit( Parser $parser ) {
		$parser->setHook( 'randomuserswithavatars', [ 'RandomUsersWithAvatars', 'getRandomUsersWithAvatars' ] );
		$parser->setHook( 'newusers', [ 'NewUsersList', 'getNewUsers' ] );
	}

	/**
	 * Add a class to the <body> element on user pages to indicate which type
	 * of user page -- social profile or traditional wiki user page -- has been
	 * chosen by the user in question to make CSS styling easier.
	 *
	 * @see https://phabricator.wikimedia.org/T167506
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @param array &$bodyAttrs Pre-existing attributes of the <body> tag
	 */
	public static function onOutputPageBodyAttributes( $out, $skin, array &$bodyAttrs ) {
		global $wgUserPageChoice;

		$title = $out->getTitle();
		$pageTitle = $title->getText();
		// Only NS_USER is "ambiguous", NS_USER_PROFILE and NS_USER_WIKI are not
		// Also we don't care about subpages here since only the main user page
		// can be something else than wikitext
		// Also ignore anonymous users since they can't have social profiles and
		// passing an IP address to UserProfile's constructor would break things
		if (
			$title->inNamespace( NS_USER ) &&
			!$title->isSubpage() &&
			$wgUserPageChoice &&
			!User::isIP( $pageTitle )
		) {
			$profile = new UserProfile( $pageTitle );
			$profile_data = $profile->getProfile();

			if ( isset( $profile_data['actor'] ) && $profile_data['actor'] ) {
				if ( $profile_data['user_page_type'] == 0 ) {
					$bodyAttrs['class'] .= ' mw-wiki-user-page';
				} else {
					$bodyAttrs['class'] .= ' mw-social-profile-page';
				}
			}
		}
	}

	/**
	 * Called by ArticleFromTitle hook
	 * Calls UserProfilePage instead of standard article on registered users'
	 * User: or User_profile: pages which are not subpages
	 *
	 * @param Title $title
	 * @param Article|null &$article
	 * @param IContextSource $context
	 */
	public static function onArticleFromTitle( Title $title, &$article, $context ) {
		global $wgHooks, $wgUserPageChoice;

		$out = $context->getOutput();
		$request = $context->getRequest();
		$pageTitle = $title->getText();

		if (
			!$title->isSubpage() &&
			$title->inNamespaces( [ NS_USER, NS_USER_PROFILE ] ) &&
			!User::isIP( $pageTitle )
		) {
			$show_user_page = false;
			if ( $wgUserPageChoice ) {
				$profile = new UserProfile( $pageTitle );
				$profile_data = $profile->getProfile();

				// If they want regular page, ignore this hook
				if ( isset( $profile_data['actor'] ) && $profile_data['actor'] && $profile_data['user_page_type'] == 0 ) {
					$show_user_page = true;
				}
			}

			if ( !$show_user_page ) {
				// Prevents editing of userpage
				if ( $request->getVal( 'action' ) == 'edit' ) {
					$out->redirect( $title->getFullURL() );
				}
			} else {
				$out->enableClientCache( false );
				$wgHooks['ParserLimitReportPrepare'][] = 'UserProfileHooks::onParserLimitReportPrepare';
			}

			$out->addModuleStyles( [
				'ext.socialprofile.clearfix',
				'ext.socialprofile.userprofile.css'
			] );

			$article = new UserProfilePage( $title );
		}
	}

	/**
	 * Mark page as uncacheable
	 *
	 * @param Parser $parser
	 * @param ParserOutput $output
	 */
	public static function onParserLimitReportPrepare( $parser, $output ) {
		$parser->getOutput()->updateCacheExpiry( 0 );
	}

	/**
	 * Load the necessary CSS for avatars in diffs if that feature is enabled.
	 *
	 * @param DifferenceEngine $differenceEngine
	 */
	public static function onDifferenceEngineShowDiff( $differenceEngine ) {
		global $wgUserProfileAvatarsInDiffs;
		if ( $wgUserProfileAvatarsInDiffs ) {
			$differenceEngine->getOutput()->addModuleStyles( 'ext.socialprofile.userprofile.diff' );
		}
	}

	/**
	 * Displays user avatars in diffs.
	 *
	 * This is largely based on wikiHow's /extensions/wikihow/hooks/DiffHooks.php
	 * (as of 2016-07-08) with some tweaks for SocialProfile.
	 *
	 * @author Scott Cushman@wikiHow -- original code
	 * @author Jack Phoenix, Samantha Nguyen -- modifications
	 *
	 * @param DifferenceEngine $differenceEngine
	 * @param string &$oldHeader
	 * @param string $prevLink
	 * @param string $oldMinor
	 * @param bool $diffOnly
	 * @param string $ldel
	 * @param bool $unhide
	 */
	public static function onDifferenceEngineOldHeader( $differenceEngine, &$oldHeader, $prevLink, $oldMinor, $diffOnly, $ldel, $unhide ) {
		global $wgUserProfileAvatarsInDiffs, $wgVersion;

		if ( !$wgUserProfileAvatarsInDiffs ) {
			return;
		}

		if ( version_compare( $wgVersion, '1.35', '<' ) ) {
			// Need a Revision object
			$oldRevision = $differenceEngine->mOldRev;
		} else {
			// Need a revision record object
			$oldRevision = $differenceEngine->getOldRevision();
		}

		$oldRevisionHeader = $differenceEngine->getRevisionHeader( $oldRevision, 'complete', 'old' );

		$oldRevUser = $oldRevision->getUser();
		if ( $oldRevUser instanceof User ) {
			$username = $oldRevUser->getName();
			$uid = $oldRevUser->getId();
		} else {
			$username = $oldRevision->getUserText();
			$uid = $oldRevUser; // sic!
		}
		$avatar = new wAvatar( $uid, 'l' );
		$avatarElement = $avatar->getAvatarURL( [
			'alt' => $username,
			'title' => $username,
			'class' => 'diff-avatar'
		] );

		$oldHeader = '<div id="mw-diff-otitle1"><h4>' . $oldRevisionHeader . '</h4></div>' .
			'<div id="mw-diff-otitle2">' . $avatarElement . '<div id="mw-diff-oinfo">' .
			Linker::revUserTools( $oldRevision, !$unhide ) .
			// '<br /><div id="mw-diff-odaysago">' . $differenceEngine->mOldRev->getTimestamp() . '</div>' .
			Linker::revComment( $oldRevision, !$diffOnly, !$unhide ) .
			'</div></div>' .
			'<div id="mw-diff-otitle3" class="rccomment">' . $oldMinor . $ldel . '</div>' .
			'<div id="mw-diff-otitle4">' . $prevLink . '</div>';
	}

	/**
	 * Displays user avatars in diffs.
	 *
	 * This is largely based on wikiHow's /extensions/wikihow/hooks/DiffHooks.php
	 * (as of 2016-07-08) with some tweaks for SocialProfile.
	 *
	 * @author Scott Cushman@wikiHow -- original code
	 * @author Jack Phoenix, Samantha Nguyen -- modifications
	 *
	 * @param DifferenceEngine $differenceEngine
	 * @param string &$newHeader
	 * @param string[] $formattedRevisionTools
	 * @param string $nextLink
	 * @param string $rollback
	 * @param string $newMinor
	 * @param bool $diffOnly
	 * @param string $rdel
	 * @param bool $unhide
	 */
	public static function onDifferenceEngineNewHeader( $differenceEngine, &$newHeader, $formattedRevisionTools, $nextLink, $rollback, $newMinor, $diffOnly, $rdel, $unhide ) {
		global $wgUserProfileAvatarsInDiffs, $wgVersion;

		if ( !$wgUserProfileAvatarsInDiffs ) {
			return;
		}

		if ( version_compare( $wgVersion, '1.35', '<' ) ) {
			// Need a Revision object
			$newRevision = $differenceEngine->mNewRev;
		} else {
			// Need a revision record object
			$newRevision = $differenceEngine->getNewRevision();
		}
		$newRevisionHeader =
			$differenceEngine->getRevisionHeader( $newRevision, 'complete', 'new' ) .
			' ' . implode( ' ', $formattedRevisionTools );

		$newRevUser = $newRevision->getUser();
		if ( $newRevUser instanceof User ) {
			$username = $newRevUser->getName();
			$uid = $newRevUser->getId();
		} else {
			$username = $newRevision->getUserText();
			$uid = $newRevUser; // sic!
		}
		$avatar = new wAvatar( $uid, 'l' );
		$avatarElement = $avatar->getAvatarURL( [
			'alt' => $username,
			'title' => $username,
			'class' => 'diff-avatar'
		] );

		$newHeader = '<div id="mw-diff-ntitle1"><h4>' . $newRevisionHeader . '</h4></div>' .
			'<div id="mw-diff-ntitle2">' . $avatarElement . '<div id="mw-diff-oinfo">'
			. Linker::revUserTools( $newRevision, !$unhide ) .
			" $rollback " .
			// '<br /><div id="mw-diff-ndaysago">' . $differenceEngine->mNewRev->getTimestamp() . '</div>' .
			Linker::revComment( $newRevision, !$diffOnly, !$unhide ) .
			'</div></div>' .
			'<div id="mw-diff-ntitle3" class="rccomment">' . $newMinor . $rdel . '</div>' .
			'<div id="mw-diff-ntitle4">' . $nextLink . $differenceEngine->markPatrolledLink() . '</div>';
	}

}
