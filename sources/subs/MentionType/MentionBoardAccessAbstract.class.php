<?php

/**
 * Abstract class that handles checks for board access level
 *
 * @name      ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause
 *
 * @version 1.0 Release Candidate 2
 *
 */

if (!defined('ELK'))
	die('No access...');

abstract class Mention_BoardAccess_Abstract extends Mention_Message_Abstract
{
	public function view($type, &$mentions)
	{
		foreach ($mentions as $key => $row)
		{
			// To ensure it is not done twice
			if (empty($this->_type) || $row['mention_type'] != $this->_type)
				continue;

			// These things are associated to messages and require permission checks
			$boards[$key] = $row['id_board'];

			$mentions[$key]['message'] = $this->_replaceMsg($row);
		}

		if (!empty($boards))
			return $this->_validateAccess($boards);
		else
			return false;
	}

	protected function _validateAccess($boards)
	{
		global $user_info, $modSettings;

		// Do the permissions checks and replace inappropriate messages
		require_once(SUBSDIR . '/Boards.subs.php');

		$removed = false;
		$accessibleBoards = accessibleBoards($boards);

		foreach ($boards as $key => $board)
		{
			// You can't see the board where this mention is, so we drop it from the results
			if (!in_array($board, $accessibleBoards))
			{
				$removed = true;
				unset($mentions[$key]);
			}
		}

		// If some of these mentions are no longer visible, we need to do some maintenance
		if ($removed)
		{
			if (!empty($modSettings['user_access_mentions']))
				$modSettings['user_access_mentions'] = @unserialize($modSettings['user_access_mentions']);
			else
				$modSettings['user_access_mentions'] = array();

			$modSettings['user_access_mentions'][$user_info['id']] = 0;
			updateSettings(array('user_access_mentions' => serialize($modSettings['user_access_mentions'])));
			scheduleTaskImmediate('user_access_mentions');
		}

		return $removed;
	}
}