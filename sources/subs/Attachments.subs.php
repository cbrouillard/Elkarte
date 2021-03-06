<?php

/**
 * This file handles the uploading and creation of attachments
 * as well as the auto management of the attachment directories.
 * Note to enhance documentation later:
 * attachment_type = 3 is a thumbnail, etc.
 *
 * @package   ElkArte Forum
 * @copyright ElkArte Forum contributors
 * @license   BSD http://opensource.org/licenses/BSD-3-Clause (see accompanying LICENSE.txt file)
 *
 * This file contains code covered by:
 * copyright: 2011 Simple Machines (http://www.simplemachines.org)
 *
 * @version 2.0 dev
 *
 */

use ElkArte\Cache\Cache;
use ElkArte\Errors\AttachmentErrorContext;
use ElkArte\Graphics\Image;
use ElkArte\Http\FsockFetchWebdata;
use ElkArte\User;
use ElkArte\Util;
use ElkArte\AttachmentsDirectory;
use ElkArte\Exceptions\Exception as ElkException;
use \Exception as Exception;

/**
 * Handles the actual saving of attachments to a directory.
 *
 * What it does:
 *
 * - Loops through $_FILES['attachment'] array and saves each file to the current attachments folder.
 * - Validates the save location actually exists.
 *
 * @param int|null $id_msg = null or id of the message with attachments, if any.
 *                  If null, this is an upload in progress for a new post.
 * @return bool
 * @throws \ElkArte\Exceptions\Exception
 * @package Attachments
 */
function processAttachments($id_msg = null)
{
	global $context, $modSettings, $txt, $topic, $board;

	$attach_errors = AttachmentErrorContext::context();

	// Make sure we're uploading to the right place.
	$attachmentDirectory = new AttachmentsDirectory($modSettings, database());
	try
	{
		$attachmentDirectory->automanageCheckDirectory(isset($_REQUEST['action']) && $_REQUEST['action'] == 'admin');

		$attach_current_dir = $attachmentDirectory->getCurrent();

		if (!is_dir($attach_current_dir))
		{
			$initial_error = 'attach_folder_warning';
			\ElkArte\Errors\Errors::instance()->log_error(sprintf($txt['attach_folder_admin_warning'], $attach_current_dir), 'critical');
		}
	}
	catch (Exception $e)
	{
		// If the attachments folder is not there: error.
		$initial_error = $e->getMessage();
	}

	if (!isset($initial_error) && !isset($context['attachments']['quantity']))
	{
		// If this isn't a new post, check the current attachments.
		if (!empty($id_msg))
		{
			list ($context['attachments']['quantity'], $context['attachments']['total_size']) = attachmentsSizeForMessage($id_msg);
		}
		else
		{
			$context['attachments']['quantity'] = 0;
			$context['attachments']['total_size'] = 0;
		}
	}

	// Hmm. There are still files in session.
	$ignore_temp = false;
	if (!empty($_SESSION['temp_attachments']['post']['files']) && count($_SESSION['temp_attachments']) > 1)
	{
		// Let's try to keep them. But...
		$ignore_temp = true;

		// If new files are being added. We can't ignore those
		if (!empty($_FILES['attachment']['tmp_name']))
		{
			foreach ($_FILES['attachment']['tmp_name'] as $dummy)
			{
				if (!empty($dummy))
				{
					$ignore_temp = false;
					break;
				}
			}
		}

		// Need to make space for the new files. So, bye bye.
		if (!$ignore_temp)
		{
			foreach ($_SESSION['temp_attachments'] as $attachID => $attachment)
			{
				if (strpos($attachID, 'post_tmp_' . User::$info->id . '_') !== false)
				{
					@unlink($attachment['tmp_name']);
				}
			}

			$attach_errors->activate()->addError('temp_attachments_flushed');
			$_SESSION['temp_attachments'] = array();
		}
	}

	if (!isset($_FILES['attachment']['name']))
	{
		$_FILES['attachment']['tmp_name'] = array();
	}

	if (!isset($_SESSION['temp_attachments']))
	{
		$_SESSION['temp_attachments'] = array();
	}

	// Remember where we are at. If it's anywhere at all.
	if (!$ignore_temp)
	{
		$_SESSION['temp_attachments']['post'] = array(
			'msg' => !empty($id_msg) ? $id_msg : 0,
			'last_msg' => !empty($_REQUEST['last_msg']) ? $_REQUEST['last_msg'] : 0,
			'topic' => !empty($topic) ? $topic : 0,
			'board' => !empty($board) ? $board : 0,
		);
	}

	// If we have an initial error, lets just display it.
	if (!empty($initial_error))
	{
		$_SESSION['temp_attachments']['initial_error'] = $initial_error;

		// This is a generic error
		$attach_errors->activate();
		$attach_errors->addError('attach_no_upload');

		// And delete the files 'cos they ain't going nowhere.
		foreach ($_FILES['attachment']['tmp_name'] as $n => $dummy)
		{
			if (file_exists($_FILES['attachment']['tmp_name'][$n]))
			{
				unlink($_FILES['attachment']['tmp_name'][$n]);
			}
		}

		$_FILES['attachment']['tmp_name'] = array();
	}

	// Loop through $_FILES['attachment'] array and move each file to the current attachments folder.
	foreach ($_FILES['attachment']['tmp_name'] as $n => $dummy)
	{
		if ($_FILES['attachment']['name'][$n] == '')
		{
			continue;
		}

		// First, let's first check for PHP upload errors.
		$errors = attachmentUploadChecks($n);

		// Set the names and destination for this file
		$attachID = 'post_tmp_' . User::$info->id . '_' . md5(mt_rand());
		$destName = $attach_current_dir . '/' . $attachID;

		// If we are error free, Try to move and rename the file before doing more checks on it.
		if (empty($errors))
		{
			$_SESSION['temp_attachments'][$attachID] = array(
				'name' => htmlspecialchars(basename($_FILES['attachment']['name'][$n]), ENT_COMPAT, 'UTF-8'),
				'tmp_name' => $destName,
				'attachid' => $attachID,
				'public_attachid' => 'post_tmp_' . User::$info->id . '_' . md5(mt_rand()),
				'size' => $_FILES['attachment']['size'][$n],
				'type' => $_FILES['attachment']['type'][$n],
				'id_folder' => $attachmentDirectory->currentDirectoryId(),
				'errors' => array(),
			);

			// Move the file to the attachments folder with a temp name for now.
			if (@move_uploaded_file($_FILES['attachment']['tmp_name'][$n], $destName))
			{
				@chmod($destName, 0644);
			}
			else
			{
				$_SESSION['temp_attachments'][$attachID]['errors'][] = 'attach_timeout';
				if (file_exists($_FILES['attachment']['tmp_name'][$n]))
				{
					unlink($_FILES['attachment']['tmp_name'][$n]);
				}
			}
		}
		// Upload error(s) were detected, flag the error, remove the file
		else
		{
			$_SESSION['temp_attachments'][$attachID] = array(
				'name' => htmlspecialchars(basename($_FILES['attachment']['name'][$n]), ENT_COMPAT, 'UTF-8'),
				'tmp_name' => $destName,
				'errors' => $errors,
			);

			if (file_exists($_FILES['attachment']['tmp_name'][$n]))
			{
				unlink($_FILES['attachment']['tmp_name'][$n]);
			}
		}

		// If there were no errors to this point, we apply some additional checks
		if (empty($_SESSION['temp_attachments'][$attachID]['errors']))
		{
			attachmentChecks($attachID);
		}

		// Want to correct for phonetographer photos?
		if (!empty($modSettings['attachment_autorotate']) && empty($_SESSION['temp_attachments'][$attachID]['errors']) && substr($_SESSION['temp_attachments'][$attachID]['type'], 0, 5) === 'image')
		{
			$image = new Image($_SESSION['temp_attachments'][$attachID]['tmp_name']);
			if ($image->autoRotateImage())
			{
				$image->saveImage($_SESSION['temp_attachments'][$attachID]['tmp_name'], IMAGETYPE_JPEG, 95);
				$_SESSION['temp_attachments'][$attachID]['size'] = filesize($_SESSION['temp_attachments'][$attachID]['tmp_name']);
			}
		}

		// Sort out the errors for display and delete any associated files.
		if (!empty($_SESSION['temp_attachments'][$attachID]['errors']))
		{
			$attach_errors->addAttach($attachID, $_SESSION['temp_attachments'][$attachID]['name']);
			$log_these = array('attachments_no_create', 'attachments_no_write', 'attach_timeout', 'ran_out_of_space', 'cant_access_upload_path', 'attach_0_byte_file', 'bad_attachment');

			foreach ($_SESSION['temp_attachments'][$attachID]['errors'] as $error)
			{
				if (!is_array($error))
				{
					$attach_errors->addError($error);
					if (in_array($error, $log_these))
					{
						\ElkArte\Errors\Errors::instance()->log_error($_SESSION['temp_attachments'][$attachID]['name'] . ': ' . $txt[$error], 'critical');

						// For critical errors, we don't want the file or session data to persist
						if (file_exists($_SESSION['temp_attachments'][$attachID]['tmp_name']))
						{
							unlink($_SESSION['temp_attachments'][$attachID]['tmp_name']);
						}
						unset($_SESSION['temp_attachments'][$attachID]);
					}
				}
				else
				{
					$attach_errors->addError(array($error[0], $error[1]));
				}
			}
		}
	}

	// Mod authors, finally a hook to hang an alternate attachment upload system upon
	// Upload to the current attachment folder with the file name $attachID or 'post_tmp_' . User::$info->id . '_' . md5(mt_rand())
	// Populate $_SESSION['temp_attachments'][$attachID] with the following:
	//   name => The file name
	//   tmp_name => Path to the temp file (AttachmentsDirectory->getCurrent() . '/' . $attachID).
	//   size => File size (required).
	//   type => MIME type (optional if not available on upload).
	//   id_folder => AttachmentsDirectory->currentDirectoryId
	//   errors => An array of errors (use the index of the $txt variable for that error).
	// Template changes can be done using "integrate_upload_template".
	call_integration_hook('integrate_attachment_upload');

	return $ignore_temp;
}

/**
 * Deletes a temporary attachment from the $_SESSION (and the filesystem)
 *
 * @param string $attach_id the temporary name generated when a file is uploaded
 *               and used in $_SESSION to help identify the attachment itself
 *
 * @return bool|string
 * @package Attachments
 *
 */
function removeTempAttachById($attach_id)
{
	foreach ($_SESSION['temp_attachments'] as $attachID => $attach)
	{
		if ($attachID === $attach_id)
		{
			// This file does exist, so lets terminate it!
			if (file_exists($attach['tmp_name']))
			{
				@unlink($attach['tmp_name']);
				unset($_SESSION['temp_attachments'][$attachID]);

				return true;
			}
			// Nope can't delete it if we can't find it
			else
			{
				return 'attachment_not_found';
			}
		}
	}

	return 'attachment_not_found';
}

/**
 * Finds and return a temporary attachment by its id
 *
 * @param string $attach_id the temporary name generated when a file is uploaded
 *  and used in $_SESSION to help identify the attachment itself
 *
 * @return mixed
 * @throws Exception
 * @package Attachments
 */
function getTempAttachById($attach_id)
{
	global $modSettings;

	$attach_real_id = null;

	if (empty($_SESSION['temp_attachments']))
	{
		throw new Exception('no_access');
	}

	foreach ($_SESSION['temp_attachments'] as $attachID => $val)
	{
		if ($attachID === 'post')
		{
			continue;
		}

		if ($val['public_attachid'] === $attach_id)
		{
			$attach_real_id = $attachID;
			break;
		}
	}

	if (empty($attach_real_id))
	{
		throw new Exception('no_access');
	}

	// The common name form is "post_tmp_123_0ac9a0b1fc18604e8704084656ed5f09"
	$id_attach = preg_replace('~[^0-9a-zA-Z_]~', '', $attach_real_id);

	// Permissions: only temporary attachments
	if (substr($id_attach, 0, 8) !== 'post_tmp')
	{
		throw new Exception('no_access');
	}

	// Permissions: only author is allowed.
	$pieces = explode('_', substr($id_attach, 9));

	if (!isset($pieces[0]) || $pieces[0] != User::$info->id)
	{
		throw new Exception('no_access');
	}

	$attachmentsDir = new AttachmentsDirectory($modSettings, database());

	$attach_dir = $attachmentsDir->getCurrent();

	if (file_exists($attach_dir . '/' . $attach_real_id) && isset($_SESSION['temp_attachments'][$attach_real_id]))
	{
		return $_SESSION['temp_attachments'][$attach_real_id];
	}

	throw new Exception('no_access');
}

/**
 * Checks if an uploaded file produced any appropriate error code
 *
 * What it does:
 *
 * - Checks for error codes in the error segment of the file array that is
 * created by PHP during the file upload.
 *
 * @param int $attachID
 *
 * @return array
 * @package Attachments
 *
 */
function attachmentUploadChecks($attachID)
{
	global $modSettings, $txt;

	$errors = array();

	// Did PHP create any errors during the upload processing of this file?
	if (!empty($_FILES['attachment']['error'][$attachID]))
	{
		// The file exceeds the max_filesize directive in php.ini
		if ($_FILES['attachment']['error'][$attachID] == 1)
		{
			$errors[] = array('file_too_big', array($modSettings['attachmentSizeLimit']));
		}
		// The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form.
		elseif ($_FILES['attachment']['error'][$attachID] == 2)
		{
			$errors[] = array('file_too_big', array($modSettings['attachmentSizeLimit']));
		}
		// Missing or a full a temp directory on the server
		elseif ($_FILES['attachment']['error'][$attachID] == 6)
		{
			\ElkArte\Errors\Errors::instance()->log_error($_FILES['attachment']['name'][$attachID] . ': ' . $txt['php_upload_error_6'], 'critical');
		}
		// One of many errors such as (3)partially uploaded, (4)empty file,
		else
		{
			\ElkArte\Errors\Errors::instance()->log_error($_FILES['attachment']['name'][$attachID] . ': ' . $txt['php_upload_error_' . $_FILES['attachment']['error'][$attachID]]);
		}

		// If we did not set an user error (3,4,6,7,8) to show then give them a generic one as there is
		// no need to provide back specifics of a server error, those are logged
		if (empty($errors))
		{
			$errors[] = 'attach_php_error';
		}
	}

	return $errors;
}

/**
 * Performs various checks on an uploaded file.
 *
 * What it does:
 *
 * - Requires that $_SESSION['temp_attachments'][$attachID] be properly populated.
 *
 * @param int $attachID id of the attachment to check
 *
 * @return bool
 * @throws \ElkArte\Exceptions\Exception attach_check_nag
 * @package Attachments
 *
 */
function attachmentChecks($attachID)
{
	global $modSettings, $context;

	// No data or missing data .... Not necessarily needed, but in case a mod author missed something.
	if (empty($_SESSION['temp_attachments'][$attachID]))
	{
		$error = '$_SESSION[\'temp_attachments\'][$attachID]';
	}
	elseif (empty($attachID))
	{
		$error = '$attachID';
	}
	elseif (empty($context['attachments']))
	{
		$error = '$context[\'attachments\']';
	}
	else

	// Let's get their attention.
	if (!empty($error))
	{
		throw new ElkException('attach_check_nag', 'debug', array($error));
	}

	// Just in case this slipped by the first checks, we stop it here and now
	if ($_SESSION['temp_attachments'][$attachID]['size'] == 0)
	{
		$_SESSION['temp_attachments'][$attachID]['errors'][] = 'attach_0_byte_file';

		return false;
	}

	// First, the dreaded security check. Sorry folks, but this should't be avoided
	$image = new Image($_SESSION['temp_attachments'][$attachID]['tmp_name']);
	$size = $image->getSize($_SESSION['temp_attachments'][$attachID]['tmp_name']);
	$valid_mime = getValidMimeImageType($size[2]);

	if ($valid_mime !== '')
	{
		if (!$image->checkImageContents($_SESSION['temp_attachments'][$attachID]['tmp_name']))
		{
			// It's bad. Last chance, maybe we can re-encode it?
			if (empty($modSettings['attachment_image_reencode']) || (!$image->reencodeImage($_SESSION['temp_attachments'][$attachID]['tmp_name'])))
			{
				// Nothing to do: not allowed or not successful re-encoding it.
				$_SESSION['temp_attachments'][$attachID]['errors'][] = 'bad_attachment';

				return false;
			}
			else
			{
				$_SESSION['temp_attachments'][$attachID]['size'] = $image->getFilesize();
			}
		}
	}

	$attachmentDirectory = new AttachmentsDirectory($modSettings, database());
	try
	{
		$_SESSION['temp_attachments'][$attachID] = $attachmentDirectory->checkDirSpace($_SESSION['temp_attachments'][$attachID], $attachID);
	}
	catch (Exception $e)
	{
		$_SESSION['temp_attachments'][$attachID]['errors'][] = $e->getMessage();
	}

	// Is the file too big?
	if (!empty($modSettings['attachmentSizeLimit']) && $_SESSION['temp_attachments'][$attachID]['size'] > $modSettings['attachmentSizeLimit'] * 1024)
	{
		$_SESSION['temp_attachments'][$attachID]['errors'][] = array('file_too_big', array(comma_format($modSettings['attachmentSizeLimit'], 0)));
	}

	// Check the total upload size for this post...
	$context['attachments']['total_size'] += $_SESSION['temp_attachments'][$attachID]['size'];
	if (!empty($modSettings['attachmentPostLimit']) && $context['attachments']['total_size'] > $modSettings['attachmentPostLimit'] * 1024)
	{
		$_SESSION['temp_attachments'][$attachID]['errors'][] = array('attach_max_total_file_size', array(comma_format($modSettings['attachmentPostLimit'], 0), comma_format($modSettings['attachmentPostLimit'] - (($context['attachments']['total_size'] - $_SESSION['temp_attachments'][$attachID]['size']) / 1024), 0)));
	}

	// Have we reached the maximum number of files we are allowed?
	$context['attachments']['quantity']++;

	// Set a max limit if none exists
	if (empty($modSettings['attachmentNumPerPostLimit']) && $context['attachments']['quantity'] >= 50)
	{
		$modSettings['attachmentNumPerPostLimit'] = 50;
	}

	if (!empty($modSettings['attachmentNumPerPostLimit']) && $context['attachments']['quantity'] > $modSettings['attachmentNumPerPostLimit'])
	{
		$_SESSION['temp_attachments'][$attachID]['errors'][] = array('attachments_limit_per_post', array($modSettings['attachmentNumPerPostLimit']));
	}

	// File extension check
	if (!empty($modSettings['attachmentCheckExtensions']))
	{
		$allowed = explode(',', strtolower($modSettings['attachmentExtensions']));
		foreach ($allowed as $k => $dummy)
		{
			$allowed[$k] = trim($dummy);
		}

		if (!in_array(strtolower(substr(strrchr($_SESSION['temp_attachments'][$attachID]['name'], '.'), 1)), $allowed))
		{
			$allowed_extensions = strtr(strtolower($modSettings['attachmentExtensions']), array(',' => ', '));
			$_SESSION['temp_attachments'][$attachID]['errors'][] = array('cant_upload_type', array($allowed_extensions));
		}
	}

	// Undo the math if there's an error
	if (!empty($_SESSION['temp_attachments'][$attachID]['errors']))
	{
		if (isset($context['dir_size']))
		{
			$context['dir_size'] -= $_SESSION['temp_attachments'][$attachID]['size'];
		}
		if (isset($context['dir_files']))
		{
			$context['dir_files']--;
		}

		$context['attachments']['total_size'] -= $_SESSION['temp_attachments'][$attachID]['size'];
		$context['attachments']['quantity']--;

		return false;
	}

	return true;
}

/**
 * Create an attachment, with the given array of parameters.
 *
 * What it does:
 *
 * - Adds any additional or missing parameters to $attachmentOptions.
 * - Renames the temporary file.
 * - Creates a thumbnail if the file is an image and the option enabled.
 *
 * @param mixed[] $attachmentOptions associative array of options
 *
 * @return bool
 * @package Attachments
 *
 */
function createAttachment(&$attachmentOptions)
{
	global $modSettings, $context;

	$db = database();
	$attachmentsDir = new AttachmentsDirectory($modSettings, $db);

	$image = new Image();

	// If this is an image we need to set a few additional parameters.
	$is_image = $image->isImage($attachmentOptions['tmp_name']);
	$size = $is_image ? $image->getSize($attachmentOptions['tmp_name']) : array(0, 0, 0);
	list ($attachmentOptions['width'], $attachmentOptions['height']) = $size;

	// If it's an image get the mime type right.
	if ($is_image)
	{
		$attachmentOptions['mime_type'] = getValidMimeImageType($size[2]);
	}

	// Get the hash if no hash has been given yet.
	if (empty($attachmentOptions['file_hash']))
	{
		$attachmentOptions['file_hash'] = getAttachmentFilename($attachmentOptions['name'], 0, null, true);
	}

	// Assuming no-one set the extension let's take a look at it.
	if (empty($attachmentOptions['fileext']))
	{
		$attachmentOptions['fileext'] = strtolower(strrpos($attachmentOptions['name'], '.') !== false ? substr($attachmentOptions['name'], strrpos($attachmentOptions['name'], '.') + 1) : '');
		if (strlen($attachmentOptions['fileext']) > 8 || '.' . $attachmentOptions['fileext'] == $attachmentOptions['name'])
		{
			$attachmentOptions['fileext'] = '';
		}
	}

	$db->insert('',
		'{db_prefix}attachments',
		array(
			'id_folder' => 'int', 'id_msg' => 'int', 'filename' => 'string-255', 'file_hash' => 'string-40', 'fileext' => 'string-8',
			'size' => 'int', 'width' => 'int', 'height' => 'int',
			'mime_type' => 'string-20', 'approved' => 'int',
		),
		array(
			(int) $attachmentOptions['id_folder'], (int) $attachmentOptions['post'], $attachmentOptions['name'], $attachmentOptions['file_hash'], $attachmentOptions['fileext'],
			(int) $attachmentOptions['size'], (empty($attachmentOptions['width']) ? 0 : (int) $attachmentOptions['width']), (empty($attachmentOptions['height']) ? '0' : (int) $attachmentOptions['height']),
			(!empty($attachmentOptions['mime_type']) ? $attachmentOptions['mime_type'] : ''), (int) $attachmentOptions['approved'],
		),
		array('id_attach')
	);
	$attachmentOptions['id'] = $db->insert_id('{db_prefix}attachments', 'id_attach');

	// @todo Add an error here maybe?
	if (empty($attachmentOptions['id']))
	{
		return false;
	}

	// Now that we have the attach id, let's rename this and finish up.
	$attachmentOptions['destination'] = getAttachmentFilename(basename($attachmentOptions['name']), $attachmentOptions['id'], $attachmentOptions['id_folder'], false, $attachmentOptions['file_hash']);
	rename($attachmentOptions['tmp_name'], $attachmentOptions['destination']);

	// If it's not approved then add to the approval queue.
	if (!$attachmentOptions['approved'])
	{
		$db->insert('',
			'{db_prefix}approval_queue',
			array(
				'id_attach' => 'int', 'id_msg' => 'int',
			),
			array(
				$attachmentOptions['id'], (int) $attachmentOptions['post'],
			),
			array()
		);
	}

	if (empty($modSettings['attachmentThumbnails']) || !$is_image || (empty($attachmentOptions['width']) && empty($attachmentOptions['height'])))
	{
		return true;
	}

	// Like thumbnails, do we?
	if (!empty($modSettings['attachmentThumbWidth']) && !empty($modSettings['attachmentThumbHeight']) && ($attachmentOptions['width'] > $modSettings['attachmentThumbWidth'] || $attachmentOptions['height'] > $modSettings['attachmentThumbHeight']))
	{
		if ($image->createThumbnail($attachmentOptions['destination'], $modSettings['attachmentThumbWidth'], $modSettings['attachmentThumbHeight']))
		{
			// Figure out how big we actually made it.
			$size = $image->getSize($attachmentOptions['destination'] . '_thumb');
			list ($thumb_width, $thumb_height) = $size;

			$thumb_mime = getValidMimeImageType($size[2]);
			$thumb_filename = $attachmentOptions['name'] . '_thumb';
			$thumb_size = $image->getfilesize();
			$thumb_file_hash = getAttachmentFilename($thumb_filename, 0, null, true);
			$thumb_path = $attachmentOptions['destination'] . '_thumb';

			// We should check the file size and count here since thumbs are added to the existing totals.
			$attachmentsDir->checkDirSize($thumb_size);
			$current_dir_id = $attachmentsDir->currentDirectoryId();

			// If a new folder has been already created. Gotta move this thumb there then.
			if ($attachmentsDir->isCurrentDirectoryId($attachmentOptions['id_folder']) === false)
			{
				$current_dir = $attachmentsDir->getCurrent();
				$current_dir_id = $attachmentsDir->currentDirectoryId();
				rename($thumb_path, $current_dir . '/' . $thumb_filename);
				$thumb_path = $current_dir . '/' . $thumb_filename;
			}

			// To the database we go!
			$db->insert('',
				'{db_prefix}attachments',
				array(
					'id_folder' => 'int', 'id_msg' => 'int', 'attachment_type' => 'int', 'filename' => 'string-255', 'file_hash' => 'string-40', 'fileext' => 'string-8',
					'size' => 'int', 'width' => 'int', 'height' => 'int', 'mime_type' => 'string-20', 'approved' => 'int',
				),
				array(
					$current_dir_id, (int) $attachmentOptions['post'], 3, $thumb_filename, $thumb_file_hash, $attachmentOptions['fileext'],
					$thumb_size, $thumb_width, $thumb_height, $thumb_mime, (int) $attachmentOptions['approved'],
				),
				array('id_attach')
			);
			$attachmentOptions['thumb'] = $db->insert_id('{db_prefix}attachments', 'id_attach');

			if (!empty($attachmentOptions['thumb']))
			{
				$db->query('', '
					UPDATE {db_prefix}attachments
					SET id_thumb = {int:id_thumb}
					WHERE id_attach = {int:id_attach}',
					array(
						'id_thumb' => $attachmentOptions['thumb'],
						'id_attach' => $attachmentOptions['id'],
					)
				);

				rename($thumb_path, getAttachmentFilename($thumb_filename, $attachmentOptions['thumb'], $current_dir_id, false, $thumb_file_hash));
			}
		}
	}

	return true;
}

/**
 * Get the avatar with the specified ID.
 *
 * What it does:
 *
 * - It gets avatar data (folder, name of the file, filehash, etc)
 * from the database.
 * - Must return the same values and in the same order as getAttachmentFromTopic()
 *
 * @param int $id_attach
 *
 * @return array
 * @package Attachments
 *
 */
function getAvatar($id_attach)
{
	$db = database();

	// Use our cache when possible
	$cache = array();
	if (Cache::instance()->getVar($cache, 'getAvatar_id-' . $id_attach))
	{
		$avatarData = $cache;
	}
	else
	{
		$request = $db->query('', '
			SELECT id_folder, filename, file_hash, fileext, id_attach, attachment_type, mime_type, approved, id_member
			FROM {db_prefix}attachments
			WHERE id_attach = {int:id_attach}
				AND id_member > {int:blank_id_member}
			LIMIT 1',
			array(
				'id_attach' => $id_attach,
				'blank_id_member' => 0,
			)
		);
		$avatarData = array();
		if ($db->num_rows($request) != 0)
		{
			$avatarData = $db->fetch_row($request);
		}
		$db->free_result($request);

		Cache::instance()->put('getAvatar_id-' . $id_attach, $avatarData, 900);
	}

	return $avatarData;
}

/**
 * Get the specified attachment.
 *
 * What it does:
 *
 * - This includes a check of the topic
 * - it only returns the attachment if it's indeed attached to a message in the topic given as parameter, and
 * query_see_board...
 * - Must return the same values and in the same order as getAvatar()
 *
 * @param int $id_attach
 * @param int $id_topic
 *
 * @return array
 * @package Attachments
 *
 */
function getAttachmentFromTopic($id_attach, $id_topic)
{
	$db = database();

	// Make sure this attachment is on this board.
	$request = $db->query('', '
		SELECT a.id_folder, a.filename, a.file_hash, a.fileext, a.id_attach, a.attachment_type, a.mime_type, a.approved, m.id_member
		FROM {db_prefix}attachments AS a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg AND m.id_topic = {int:current_topic})
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})
		WHERE a.id_attach = {int:attach}
		LIMIT 1',
		array(
			'attach' => $id_attach,
			'current_topic' => $id_topic,
		)
	);

	$attachmentData = array();
	if ($db->num_rows($request) != 0)
	{
		$attachmentData = $db->fetch_row($request);
	}
	$db->free_result($request);

	return $attachmentData;
}

/**
 * Get the thumbnail of specified attachment.
 *
 * What it does:
 *
 * - This includes a check of the topic
 * - it only returns the attachment if it's indeed attached to a message in the topic given as parameter, and
 * query_see_board...
 * - Must return the same values and in the same order as getAvatar()
 *
 * @param int $id_attach
 * @param int $id_topic
 *
 * @return array
 * @package Attachments
 *
 */
function getAttachmentThumbFromTopic($id_attach, $id_topic)
{
	$db = database();

	// Make sure this attachment is on this board.
	$request = $db->query('', '
		SELECT th.id_folder, th.filename, th.file_hash, th.fileext, th.id_attach, th.attachment_type, th.mime_type,
			a.id_folder AS attach_id_folder, a.filename AS attach_filename,
			a.file_hash AS attach_file_hash, a.fileext AS attach_fileext,
			a.id_attach AS attach_id_attach, a.attachment_type AS attach_attachment_type,
			a.mime_type AS attach_mime_type,
		 	a.approved, m.id_member
		FROM {db_prefix}attachments AS a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg AND m.id_topic = {int:current_topic})
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})
			LEFT JOIN {db_prefix}attachments AS th ON (th.id_attach = a.id_thumb)
		WHERE a.id_attach = {int:attach}',
		array(
			'attach' => $id_attach,
			'current_topic' => $id_topic,
		)
	);
	$attachmentData = array_fill(0, 9, '');
	if ($db->num_rows($request) != 0)
	{
		$fetch = $db->fetch_assoc($request);

		// If there is a hash then the thumbnail exists
		if (!empty($fetch['file_hash']))
		{
			$attachmentData = array(
				$fetch['id_folder'],
				$fetch['filename'],
				$fetch['file_hash'],
				$fetch['fileext'],
				$fetch['id_attach'],
				$fetch['attachment_type'],
				$fetch['mime_type'],
				$fetch['approved'],
				$fetch['id_member'],
			);
		}
		// otherwise $modSettings['attachmentThumbnails'] may be (or was) off, so original file
		elseif (getValidMimeImageType($fetch['attach_mime_type']) !== '')
		{
			$attachmentData = array(
				$fetch['attach_id_folder'],
				$fetch['attach_filename'],
				$fetch['attach_file_hash'],
				$fetch['attach_fileext'],
				$fetch['attach_id_attach'],
				$fetch['attach_attachment_type'],
				$fetch['attach_mime_type'],
				$fetch['approved'],
				$fetch['id_member'],
			);
		}
	}
	$db->free_result($request);

	return $attachmentData;
}

/**
 * Returns if the given attachment ID is an image file or not
 *
 * What it does:
 *
 * - Given an attachment id, checks that it exists as an attachment
 * - Verifies the message its associated is on a board the user can see
 * - Sets 'is_image' if the attachment is an image file
 * - Returns basic attachment values
 *
 * @param int $id_attach
 *
 * @returns array|boolean
 * @package Attachments
 */
function isAttachmentImage($id_attach)
{
	$db = database();

	// Make sure this attachment is on this board.
	$request = $db->query('', '
		SELECT
			a.filename, a.fileext, a.id_attach, a.attachment_type, a.mime_type, a.approved, a.downloads, a.size, a.width, a.height,
			m.id_topic, m.id_board
		FROM {db_prefix}attachments as a
			INNER JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
			INNER JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board AND {query_see_board})
		WHERE id_attach = {int:attach}
			AND attachment_type = {int:type}
			AND a.approved = {int:approved}
		LIMIT 1',
		array(
			'attach' => $id_attach,
			'approved' => 1,
			'type' => 0,
		)
	);
	$attachmentData = array();
	if ($db->num_rows($request) != 0)
	{
		$attachmentData = $db->fetch_assoc($request);
		$attachmentData['is_image'] = substr($attachmentData['mime_type'], 0, 5) === 'image';
		$attachmentData['size'] = byte_format($attachmentData['size']);
	}
	$db->free_result($request);

	return !empty($attachmentData) ? $attachmentData : false;
}

/**
 * Increase download counter for id_attach.
 *
 * What it does:
 *
 * - Does not check if it's a thumbnail.
 *
 * @param int $id_attach
 * @package Attachments
 */
function increaseDownloadCounter($id_attach)
{
	$db = database();

	$db->query('attach_download_increase', '
		UPDATE LOW_PRIORITY {db_prefix}attachments
		SET downloads = downloads + 1
		WHERE id_attach = {int:id_attach}',
		array(
			'id_attach' => $id_attach,
		)
	);
}

/**
 * Saves a file and stores it locally for avatar use by id_member.
 *
 * What it does:
 *
 * - supports GIF, JPG, PNG, BMP and WBMP formats.
 * - detects if GD2 is available.
 * - uses resizeImageFile() to resize to max_width by max_height, and saves the result to a file.
 * - updates the database info for the member's avatar.
 * - returns whether the download and resize was successful.
 *
 * @param string $temporary_path the full path to the temporary file
 * @param int $memID member ID
 * @param int $max_width
 * @param int $max_height
 * @return boolean whether the download and resize was successful.
 * @throws \ElkArte\Exceptions\Exception
 * @package Attachments
 */
function saveAvatar($temporary_path, $memID, $max_width, $max_height)
{
	global $modSettings;

	$db = database();

	$ext = !empty($modSettings['avatar_download_png']) ? 'png' : 'jpeg';
	$destName = 'avatar_' . $memID . '_' . time() . '.' . $ext;

	// Just making sure there is a non-zero member.
	if (empty($memID))
	{
		return false;
	}

	require_once(SUBSDIR . '/ManageAttachments.subs.php');
	removeAttachments(array('id_member' => $memID));

	$attachmentsDir = new AttachmentsDirectory($modSettings, $db);

	$id_folder = $attachmentsDir->currentDirectoryId();
	$avatar_hash = empty($modSettings['custom_avatar_enabled']) ? getAttachmentFilename($destName, 0, null, true) : '';
	$db->insert('',
		'{db_prefix}attachments',
		array(
			'id_member' => 'int', 'attachment_type' => 'int', 'filename' => 'string-255', 'file_hash' => 'string-255', 'fileext' => 'string-8', 'size' => 'int',
			'id_folder' => 'int',
		),
		array(
			$memID, empty($modSettings['custom_avatar_enabled']) ? 0 : 1, $destName, $avatar_hash, $ext, 1,
			$id_folder,
		),
		array('id_attach')
	);
	$attachID = $db->insert_id('{db_prefix}attachments', 'id_attach');

	// The destination filename will depend on whether custom dir for avatars has been set
	$destName = getAvatarPath() . '/' . $destName;
	$path = $attachmentsDir->getCurrent();
	$destName = empty($avatar_hash) ? $destName : $path . '/' . $attachID . '_' . $avatar_hash . '.elk';
	$format = !empty($modSettings['avatar_download_png']) ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

	// Resize it.
	$image = new Image();
	if ($image->createThumbnail($temporary_path, $max_width, $max_height, $destName, $format))
	{
		list ($width, $height) = $image->getSize($destName);
		$mime_type = getValidMimeImageType($ext);

		// Write filesize in the database.
		$db->query('', '
			UPDATE {db_prefix}attachments
			SET size = {int:filesize}, width = {int:width}, height = {int:height},
				mime_type = {string:mime_type}
			WHERE id_attach = {int:current_attachment}',
			array(
				'filesize' => $image->getFilesize(),
				'width' => (int) $width,
				'height' => (int) $height,
				'current_attachment' => $attachID,
				'mime_type' => $mime_type,
			)
		);

		// Retain this globally in case the script wants it.
		$modSettings['new_avatar_data'] = array(
			'id' => $attachID,
			'filename' => $destName,
			'type' => empty($modSettings['custom_avatar_enabled']) ? 0 : 1,
		);

		return true;
	}
	else
	{
		$db->query('', '
			DELETE FROM {db_prefix}attachments
			WHERE id_attach = {int:current_attachment}',
			array(
				'current_attachment' => $attachID,
			)
		);

		return false;
	}
}

/**
 * Get the size of a specified image with better error handling.
 *
 * What it does:
 *
 * - Uses getimagesize() to determine the size of a file.
 * - Attempts to connect to the server first so it won't time out.
 *
 * @param string $url
 * @return mixed[]|bool the image size as array(width, height), or false on failure
 * @package Attachments
 */
function url_image_size($url)
{
	// Can we pull this from the cache... please please?
	$temp = array();
	if (Cache::instance()->getVar($temp, 'url_image_size-' . md5($url), 240))
	{
		return $temp;
	}

	$url_path = parse_url($url, PHP_URL_PATH);
	$extension = pathinfo($url_path, PATHINFO_EXTENSION);

	switch ($extension)
	{
		case 'jpg':
		case 'jpeg':
			// Size location block is variable, so we fetch a meaningful chunk
			$range = 32768;
			break;
		case 'png':
			// Size will be in the first 24 bytes
			$range = 1024;
			break;
		case 'gif':
			// Size will be in the first 10 bytes
			$range = 1024;
			break;
		case 'bmp':
			// Size will be in the first 32 bytes
			$range = 1024;
			break;
		default:
			$range = 16384;
	}

	$image = new FsockFetchWebdata(array('max_length' => $range));
	$image->get_url_data($url);

	// The server may not understand Range: so lets try to fetch the entire thing
	// assuming we were not simply turned away
	if ($image->result('code') != 200 && $image->result('code') != 403)
	{
		$image = new FsockFetchWebdata(array());
		$image->get_url_data($url);
	}

	// Here is the data, getimagesizefromstring does not care if its a complete image, it only
	// searches for size headers in a given data set.
	$data = $image->result('body');
	$size = getimagesizefromstring($data);

	// Well, ok, umm, fail!
	if ($data === false || $size === false)
	{
		return array(-1, -1, -1);
	}

	Cache::instance()->put('url_image_size-' . md5($url), $size, 240);

	return $size;
}

/**
 * The avatars path: if custom avatar directory is set, that's it.
 * Otherwise, it's attachments path.
 *
 * @return string
 * @package Attachments
 */
function getAvatarPath()
{
	global $modSettings;

	if (empty($modSettings['custom_avatar_enabled']))
	{
		$attachmentsDir = new AttachmentsDirectory($modSettings, database());
		return $attachmentsDir->getCurrent();
	}
	else
	{
		return $modSettings['custom_avatar_dir'];
	}
}

/**
 * Returns the ID of the folder avatars are currently saved in.
 *
 * @return int 1 if custom avatar directory is enabled,
 * and the ID of the current attachment folder otherwise.
 * NB: the latter could also be 1.
 * @package Attachments
 */
function getAvatarPathID()
{
	global $modSettings;

	// Little utility function for the endless $id_folder computation for avatars.
	if (empty($modSettings['custom_avatar_enabled']))
	{
		$attachmentsDir = new AttachmentsDirectory($modSettings, database());
		return $attachmentsDir->currentDirectoryId();
	}
	else
	{
		return 1;
	}
}

/**
 * Get all attachments associated with a set of posts.
 *
 * What it does:
 *  - This does not check permissions.
 *
 * @param int[] $messages array of messages ids
 * @param bool $includeUnapproved = false
 * @param string|null $filter name of a callback function
 * @param mixed[] $all_posters
 *
 * @return array
 * @package Attachments
 *
 */
function getAttachments($messages, $includeUnapproved = false, $filter = null, $all_posters = array())
{
	global $modSettings;

	$db = database();

	$attachments = array();
	$request = $db->query('', '
		SELECT
			a.id_attach, a.id_folder, a.id_msg, a.filename, a.file_hash, COALESCE(a.size, 0) AS filesize, a.downloads, a.approved,
			a.width, a.height' . (empty($modSettings['attachmentShowImages']) || empty($modSettings['attachmentThumbnails']) ? '' : ',
			COALESCE(thumb.id_attach, 0) AS id_thumb, thumb.width AS thumb_width, thumb.height AS thumb_height') . '
			FROM {db_prefix}attachments AS a' . (empty($modSettings['attachmentShowImages']) || empty($modSettings['attachmentThumbnails']) ? '' : '
			LEFT JOIN {db_prefix}attachments AS thumb ON (thumb.id_attach = a.id_thumb)') . '
		WHERE a.id_msg IN ({array_int:message_list})
			AND a.attachment_type = {int:attachment_type}',
		array(
			'message_list' => $messages,
			'attachment_type' => 0,
		)
	);
	$temp = array();
	while ($row = $db->fetch_assoc($request))
	{
		if (!$row['approved'] && !$includeUnapproved && (empty($filter) || !call_user_func($filter, $row, $all_posters)))
		{
			continue;
		}

		$temp[$row['id_attach']] = $row;

		if (!isset($attachments[$row['id_msg']]))
		{
			$attachments[$row['id_msg']] = array();
		}
	}
	$db->free_result($request);

	// This is better than sorting it with the query...
	ksort($temp);

	foreach ($temp as $row)
	{
		$attachments[$row['id_msg']][] = $row;
	}

	return $attachments;
}

/**
 * Recursive function to retrieve server-stored avatar files
 *
 * @param string $directory
 * @param int $level
 * @return array
 * @package Attachments
 */
function getServerStoredAvatars($directory, $level)
{
	global $context, $txt, $modSettings;

	$result = array();

	// Open the directory..
	$dir = dir($modSettings['avatar_directory'] . (!empty($directory) ? '/' : '') . $directory);
	$dirs = array();
	$files = array();

	if (!$dir)
	{
		return array();
	}

	while ($line = $dir->read())
	{
		if (in_array($line, array('.', '..', 'blank.png', 'index.php')))
		{
			continue;
		}

		if (is_dir($modSettings['avatar_directory'] . '/' . $directory . (!empty($directory) ? '/' : '') . $line))
		{
			$dirs[] = $line;
		}
		else
		{
			$files[] = $line;
		}
	}
	$dir->close();

	// Sort the results...
	natcasesort($dirs);
	natcasesort($files);

	if ($level == 0)
	{
		$result[] = array(
			'filename' => 'blank.png',
			'checked' => in_array($context['member']['avatar']['server_pic'], array('', 'blank.png')),
			'name' => $txt['no_pic'],
			'is_dir' => false
		);
	}

	foreach ($dirs as $line)
	{
		$tmp = getServerStoredAvatars($directory . (!empty($directory) ? '/' : '') . $line, $level + 1);
		if (!empty($tmp))
		{
			$result[] = array(
				'filename' => htmlspecialchars($line, ENT_COMPAT, 'UTF-8'),
				'checked' => strpos($context['member']['avatar']['server_pic'], $line . '/') !== false,
				'name' => '[' . htmlspecialchars(str_replace('_', ' ', $line), ENT_COMPAT, 'UTF-8') . ']',
				'is_dir' => true,
				'files' => $tmp
			);
		}
		unset($tmp);
	}

	foreach ($files as $line)
	{
		$filename = substr($line, 0, (strlen($line) - strlen(strrchr($line, '.'))));
		$extension = substr(strrchr($line, '.'), 1);

		// Make sure it is an image.
		if (getValidMimeImageType($extension) === '')
		{
			continue;
		}

		$result[] = array(
			'filename' => htmlspecialchars($line, ENT_COMPAT, 'UTF-8'),
			'checked' => $line == $context['member']['avatar']['server_pic'],
			'name' => htmlspecialchars(str_replace('_', ' ', $filename), ENT_COMPAT, 'UTF-8'),
			'is_dir' => false
		);
		if ($level == 1)
		{
			$context['avatar_list'][] = $directory . '/' . $line;
		}
	}

	return $result;
}

/**
 * Update an attachment's thumbnail
 *
 * @param string $filename
 * @param int $id_attach
 * @param int $id_msg
 * @param int $old_id_thumb = 0
 * @param string $real_filename
 * @return array The updated information
 * @throws \ElkArte\Exceptions\Exception
 * @package Attachments
 */
function updateAttachmentThumbnail($filename, $id_attach, $id_msg, $old_id_thumb = 0, $real_filename = '')
{
	global $modSettings;

	$attachment = array('id_attach' => $id_attach);

	$image = new Image();
	if ($image->createThumbnail($filename, $modSettings['attachmentThumbWidth'], $modSettings['attachmentThumbHeight']))
	{
		// So what folder are we putting this image in?
		$attachmentsDir = new AttachmentsDirectory($modSettings, database());
		$id_folder_thumb = $attachmentsDir->currentDirectoryId();

		// Calculate the size of the created thumbnail.
		$size = $image->getSize($filename . '_thumb');
		list ($attachment['thumb_width'], $attachment['thumb_height']) = $size;
		$thumb_size = $image->getfilesize();

		// Figure out the mime type.
		$thumb_mime = getValidMimeImageType($size[2]);
		$thumb_ext = substr($thumb_mime, strpos($thumb_mime, '/') + 1);

		$thumb_filename = (!empty($real_filename) ? $real_filename : $filename) . '_thumb';
		$thumb_hash = getAttachmentFilename($thumb_filename, 0, null, true);

		$db = database();

		// Add this beauty to the database.
		$db->insert('',
			'{db_prefix}attachments',
			array('id_folder' => 'int', 'id_msg' => 'int', 'attachment_type' => 'int', 'filename' => 'string-255', 'file_hash' => 'string-40', 'size' => 'int', 'width' => 'int', 'height' => 'int', 'fileext' => 'string-8', 'mime_type' => 'string-255'),
			array($id_folder_thumb, $id_msg, 3, $thumb_filename, $thumb_hash, (int) $thumb_size, (int) $attachment['thumb_width'], (int) $attachment['thumb_height'], $thumb_ext, $thumb_mime),
			array('id_attach')
		);

		$attachment['id_thumb'] = $db->insert_id('{db_prefix}attachments', 'id_attach');
		if (!empty($attachment['id_thumb']))
		{
			$db->query('', '
				UPDATE {db_prefix}attachments
				SET id_thumb = {int:id_thumb}
				WHERE id_attach = {int:id_attach}',
				array(
					'id_thumb' => $attachment['id_thumb'],
					'id_attach' => $attachment['id_attach'],
				)
			);

			$thumb_realname = getAttachmentFilename($thumb_filename, $attachment['id_thumb'], $id_folder_thumb, false, $thumb_hash);
			rename($filename . '_thumb', $thumb_realname);

			// Do we need to remove an old thumbnail?
			if (!empty($old_id_thumb))
			{
				require_once(SUBSDIR . '/ManageAttachments.subs.php');
				removeAttachments(array('id_attach' => $old_id_thumb), '', false, false);
			}
		}
	}

	return $attachment;
}

/**
 * Compute and return the total size of attachments to a single message.
 *
 * @param int $id_msg
 * @param bool $include_count = true if true, it also returns the attachments count
 * @package Attachments
 */
function attachmentsSizeForMessage($id_msg, $include_count = true)
{
	$db = database();

	if ($include_count)
	{
		$request = $db->query('', '
			SELECT COUNT(*), SUM(size)
			FROM {db_prefix}attachments
			WHERE id_msg = {int:id_msg}
				AND attachment_type = {int:attachment_type}',
			array(
				'id_msg' => $id_msg,
				'attachment_type' => 0,
			)
		);
	}
	else
	{
		$request = $db->query('', '
			SELECT COUNT(*)
			FROM {db_prefix}attachments
			WHERE id_msg = {int:id_msg}
				AND attachment_type = {int:attachment_type}',
			array(
				'id_msg' => $id_msg,
				'attachment_type' => 0,
			)
		);
	}
	$size = $db->fetch_row($request);
	$db->free_result($request);

	return $size;
}

/**
 * This loads an attachment's contextual data including, most importantly, its size if it is an image.
 *
 * What it does:
 *
 * - Pre-condition: $attachments array to have been filled with the proper attachment data, as Display() does.
 * - It requires the view_attachments permission to calculate image size.
 * - It attempts to keep the "aspect ratio" of the posted image in line, even if it has to be resized by
 * the max_image_width and max_image_height settings.
 *
 * @param int $id_msg message number to load attachments for
 * @return array of attachments
 * @todo change this pre-condition, too fragile and error-prone.
 *
 * @package Attachments
 */
function loadAttachmentContext($id_msg)
{
	global $attachments, $modSettings, $scripturl, $topic;

	// Set up the attachment info - based on code by Meriadoc.
	$attachmentData = array();
	$have_unapproved = false;
	if (isset($attachments[$id_msg]) && !empty($modSettings['attachmentEnable']))
	{
		foreach ($attachments[$id_msg] as $i => $attachment)
		{
			$attachmentData[$i] = array(
				'id' => $attachment['id_attach'],
				'name' => preg_replace('~&amp;#(\\d{1,7}|x[0-9a-fA-F]{1,6});~', '&#\\1;', htmlspecialchars($attachment['filename'], ENT_COMPAT, 'UTF-8')),
				'downloads' => $attachment['downloads'],
				'size' => byte_format($attachment['filesize']),
				'byte_size' => $attachment['filesize'],
				'href' => $scripturl . '?action=dlattach;topic=' . $topic . '.0;attach=' . $attachment['id_attach'],
				'link' => '<a href="' . $scripturl . '?action=dlattach;topic=' . $topic . '.0;attach=' . $attachment['id_attach'] . '">' . htmlspecialchars($attachment['filename'], ENT_COMPAT, 'UTF-8') . '</a>',
				'is_image' => !empty($attachment['width']) && !empty($attachment['height']) && !empty($modSettings['attachmentShowImages']),
				'is_approved' => $attachment['approved'],
				'file_hash' => $attachment['file_hash'],
			);

			// If something is unapproved we'll note it so we can sort them.
			if (!$attachment['approved'])
			{
				$have_unapproved = true;
			}

			if (!$attachmentData[$i]['is_image'])
			{
				continue;
			}

			$attachmentData[$i]['real_width'] = $attachment['width'];
			$attachmentData[$i]['width'] = $attachment['width'];
			$attachmentData[$i]['real_height'] = $attachment['height'];
			$attachmentData[$i]['height'] = $attachment['height'];

			// Let's see, do we want thumbs?
			if (!empty($modSettings['attachmentThumbnails']) && !empty($modSettings['attachmentThumbWidth']) && !empty($modSettings['attachmentThumbHeight']) && ($attachment['width'] > $modSettings['attachmentThumbWidth'] || $attachment['height'] > $modSettings['attachmentThumbHeight']) && strlen($attachment['filename']) < 249)
			{
				// A proper thumb doesn't exist yet? Create one! Or, it needs update.
				if (empty($attachment['id_thumb']) || $attachment['thumb_width'] > $modSettings['attachmentThumbWidth'] || $attachment['thumb_height'] > $modSettings['attachmentThumbHeight'] || ($attachment['thumb_width'] < $modSettings['attachmentThumbWidth'] && $attachment['thumb_height'] < $modSettings['attachmentThumbHeight']))
				{
					$filename = getAttachmentFilename($attachment['filename'], $attachment['id_attach'], $attachment['id_folder'], false, $attachment['file_hash']);
					$attachment = array_merge($attachment, updateAttachmentThumbnail($filename, $attachment['id_attach'], $id_msg, $attachment['id_thumb'], $attachment['filename']));
				}

				// Only adjust dimensions on successful thumbnail creation.
				if (!empty($attachment['thumb_width']) && !empty($attachment['thumb_height']))
				{
					$attachmentData[$i]['width'] = $attachment['thumb_width'];
					$attachmentData[$i]['height'] = $attachment['thumb_height'];
				}
			}

			if (!empty($attachment['id_thumb']))
			{
				$attachmentData[$i]['thumbnail'] = array(
					'id' => $attachment['id_thumb'],
					'href' => $scripturl . '?action=dlattach;topic=' . $topic . '.0;attach=' . $attachment['id_thumb'] . ';image',
				);
			}
			$attachmentData[$i]['thumbnail']['has_thumb'] = !empty($attachment['id_thumb']);

			// If thumbnails are disabled, check the maximum size of the image.
			if (!$attachmentData[$i]['thumbnail']['has_thumb'] && ((!empty($modSettings['max_image_width']) && $attachment['width'] > $modSettings['max_image_width']) || (!empty($modSettings['max_image_height']) && $attachment['height'] > $modSettings['max_image_height'])))
			{
				if (!empty($modSettings['max_image_width']) && (empty($modSettings['max_image_height']) || $attachment['height'] * $modSettings['max_image_width'] / $attachment['width'] <= $modSettings['max_image_height']))
				{
					$attachmentData[$i]['width'] = $modSettings['max_image_width'];
					$attachmentData[$i]['height'] = floor($attachment['height'] * $modSettings['max_image_width'] / $attachment['width']);
				}
				elseif (!empty($modSettings['max_image_width']))
				{
					$attachmentData[$i]['width'] = floor($attachment['width'] * $modSettings['max_image_height'] / $attachment['height']);
					$attachmentData[$i]['height'] = $modSettings['max_image_height'];
				}
			}
			elseif ($attachmentData[$i]['thumbnail']['has_thumb'])
			{
				// Data attributes for use in expandThumb
				$attachmentData[$i]['thumbnail']['lightbox'] = 'data-lightboxmessage="' . $id_msg . '" data-lightboximage="' . $attachment['id_attach'] . '"';

				// If the image is too large to show inline, make it a popup.
				// @todo this needs to be removed or depreciated
				if (((!empty($modSettings['max_image_width']) && $attachmentData[$i]['real_width'] > $modSettings['max_image_width']) || (!empty($modSettings['max_image_height']) && $attachmentData[$i]['real_height'] > $modSettings['max_image_height'])))
				{
					$attachmentData[$i]['thumbnail']['javascript'] = 'return reqWin(\'' . $attachmentData[$i]['href'] . ';image\', ' . ($attachment['width'] + 20) . ', ' . ($attachment['height'] + 20) . ', true);';
				}
				else
				{
					$attachmentData[$i]['thumbnail']['javascript'] = 'return expandThumb(' . $attachment['id_attach'] . ');';
				}
			}

			if (!$attachmentData[$i]['thumbnail']['has_thumb'])
			{
				$attachmentData[$i]['downloads']++;
			}
		}
	}

	// Do we need to instigate a sort?
	if ($have_unapproved)
	{
		usort($attachmentData, 'approved_attach_sort');
	}

	return $attachmentData;
}

/**
 * A sort function for putting unapproved attachments first.
 *
 * @param mixed[] $a
 * @param mixed[] $b
 * @return int -1, 0, 1
 * @package Attachments
 */
function approved_attach_sort($a, $b)
{
	if ($a['is_approved'] === $b['is_approved'])
	{
		return 0;
	}

	return $a['is_approved'] > $b['is_approved'] ? -1 : 1;
}

/**
 * Callback filter for the retrieval of attachments.
 *
 * What it does:
 * This function returns false when:
 *  - the attachment is unapproved, and
 *  - the viewer is not the poster of the message where the attachment is
 *
 * @param mixed[] $attachment_info
 * @param mixed[] $all_posters
 *
 * @return bool
 * @package Attachments
 *
 */
function filter_accessible_attachment($attachment_info, $all_posters)
{
	return !(!$attachment_info['approved'] && (!isset($all_posters[$attachment_info['id_msg']]) || $all_posters[$attachment_info['id_msg']] != User::$info->id));
}

/**
 * Older attachments may still use this function.
 *
 * @param string $filename
 * @param int $attachment_id
 * @param string|null $dir
 * @param boolean $new
 *
 * @return null|string|string[]
 * @package Attachments
 *
 */
function getLegacyAttachmentFilename($filename, $attachment_id, $dir = null, $new = false)
{
	global $modSettings;

	$clean_name = $filename;

	// Sorry, no spaces, dots, or anything else but letters allowed.
	$clean_name = preg_replace(array('/\s/', '/[^\w_\.\-]/'), array('_', ''), $clean_name);

	$enc_name = $attachment_id . '_' . strtr($clean_name, '.', '_') . md5($clean_name);
	$clean_name = preg_replace('~\.[\.]+~', '.', $clean_name);

	if (empty($attachment_id) || ($new && empty($modSettings['attachmentEncryptFilenames'])))
	{
		return $clean_name;
	}
	elseif ($new)
	{
		return $enc_name;
	}

	$attachmentsDir = new AttachmentsDirectory($modSettings, database());
	$path = $attachmentsDir->getCurrent();

	$filename = file_exists($path . '/' . $enc_name) ? $path . '/' . $enc_name : $path . '/' . $clean_name;

	return $filename;
}

/**
 * Binds a set of attachments to a message.
 *
 * @param int $id_msg
 * @param int[] $attachment_ids
 * @package Attachments
 */
function bindMessageAttachments($id_msg, $attachment_ids)
{
	$db = database();

	$db->query('', '
		UPDATE {db_prefix}attachments
		SET id_msg = {int:id_msg}
		WHERE id_attach IN ({array_int:attachment_list})',
		array(
			'attachment_list' => $attachment_ids,
			'id_msg' => $id_msg,
		)
	);
}

/**
 * Get an attachment's encrypted filename. If $new is true, won't check for file existence.
 *
 * - If new is set returns a hash for the db
 * - If no file hash is supplied, determines one and returns it
 * - Returns the path to the file
 *
 * @param string $filename The name of the file
 * @param int|null $attachment_id The ID of the attachment
 * @param string|null $dir Which directory it should be in (null to use current)
 * @param bool $new If this is a new attachment, if so just returns a hash
 * @param string $file_hash The file hash
 *
 * @return null|string|string[]
 * @todo this currently returns the hash if new, and the full filename otherwise.
 * Something messy like that.
 * @todo and of course everything relies on this behavior and work around it. :P.
 * Converters included.
 *
 */
function getAttachmentFilename($filename, $attachment_id, $dir = null, $new = false, $file_hash = '')
{
	global $modSettings;

	// Just make up a nice hash...
	if ($new)
	{
		return hash('sha1', hash('md5', $filename . time()) . mt_rand());
	}

	// In case of files from the old system, do a legacy call.
	if (empty($file_hash))
	{
		return getLegacyAttachmentFilename($filename, $attachment_id, $dir, $new);
	}

	$attachmentsDir = new AttachmentsDirectory($modSettings, database());
	$path = $attachmentsDir->getCurrent();

	return $path . '/' . $attachment_id . '_' . $file_hash . '.elk';
}

/**
 * Returns the board and the topic the attachment belongs to.
 *
 * @param int $id_attach
 * @return int[]|boolean on fail else an array of id_board, id_topic
 * @package Attachments
 */
function getAttachmentPosition($id_attach)
{
	$db = database();

	// Make sure this attachment is on this board.
	$request = $db->query('', '
		SELECT 
			m.id_board, m.id_topic
		FROM {db_prefix}attachments AS a
			LEFT JOIN {db_prefix}messages AS m ON (m.id_msg = a.id_msg)
			LEFT JOIN {db_prefix}boards AS b ON (b.id_board = m.id_board)
		WHERE a.id_attach = {int:attach}
			AND {query_see_board}
		LIMIT 1',
		array(
			'attach' => $id_attach,
		)
	);

	$attachmentData = $db->fetch_assoc($request);
	$db->free_result($request);

	if (empty($attachmentData))
	{
		return false;
	}
	else
	{
		return $attachmentData;
	}
}

/**
 * Simple wrapper for getimagesize
 *
 * @param string $file
 * @param string|boolean $error return array or false on error
 *
 * @return array|boolean
 */
function elk_getimagesize($file, $error = 'array')
{
	$sizes = @getimagesize($file);

	// Can't get it, what shall we return
	if (empty($sizes))
	{
		$sizes = $error === 'array' ? array(-1, -1, -1) : false;
	}

	return $sizes;
}

/**
 * Checks if we have a known and support mime-type for which we have a thumbnail image
 *
 * @param string $file_ext
 * @param bool $url
 *
 * @return bool|string
 */
function returnMimeThumb($file_ext, $url = false)
{
	global $settings;

	// These are not meant to be exhaustive, just some of the most common attached on a forum
	static $generics = array(
		'arc' => array('tgz', 'zip', 'rar', '7z', 'gz'),
		'doc' => array('doc', 'docx', 'wpd', 'odt'),
		'sound' => array('wav', 'mp3', 'pcm', 'aiff', 'wma', 'm4a'),
		'video' => array('mp4', 'mgp', 'mpeg', 'mp4', 'wmv', 'flv', 'aiv', 'mov', 'swf'),
		'txt' => array('rtf', 'txt', 'log'),
		'presentation' => array('ppt', 'pps', 'odp'),
		'spreadsheet' => array('xls', 'xlr', 'ods'),
		'web' => array('html', 'htm')
	);
	foreach ($generics as $generic_extension => $generic_types)
	{
		if (in_array($file_ext, $generic_types))
		{
			$file_ext = $generic_extension;
			break;
		}
	}

	static $distinct = array('arc', 'doc', 'sound', 'video', 'txt', 'presentation', 'spreadsheet', 'web',
							 'c', 'cpp', 'css', 'csv', 'java', 'js', 'pdf', 'php', 'sql', 'xml');

	if (empty($settings))
	{
		theme()->getTemplates()->loadEssentialThemeData();
	}

	// Return the mine thumbnail if it exists or just the default
	if (!in_array($file_ext, $distinct) || !file_exists($settings['theme_dir'] . '/images/mime_images/' . $file_ext . '.png'))
	{
		$file_ext = 'default';
	}

	$location = $url ? $settings['theme_url'] : $settings['theme_dir'];

	return $location . '/images/mime_images/' . $file_ext . '.png';
}

/**
 * Finds in $_SESSION['temp_attachments'] an attachment id from its public id
 *
 * @param string $public_attachid
 *
 * @return string
 */
function getAttachmentIdFromPublic($public_attachid)
{
	if (empty($_SESSION['temp_attachments']))
	{
		return $public_attachid;
	}

	foreach ($_SESSION['temp_attachments'] as $key => $val)
	{
		if (isset($val['public_attachid']) && $val['public_attachid'] === $public_attachid)
		{
			return $key;
		}
	}

	return $public_attachid;
}

/**
 * From either a mime type, an extension or an IMAGETYPE_* constant
 * returns a valid image mime type
 *
 * @param string $mime
 *
 * @return string
 */
function getValidMimeImageType($mime)
{
	// These are the only valid image types.
	static $validImageTypes = array(
		-1 => 'jpg',
		// Starting from here are the IMAGETYPE_* constants
		IMAGETYPE_GIF => 'gif',
		IMAGETYPE_JPEG => 'jpeg',
		IMAGETYPE_PNG => 'png',
		IMAGETYPE_PSD => 'psd',
		IMAGETYPE_BMP => 'bmp',
		IMAGETYPE_TIFF_II => 'tiff',
		IMAGETYPE_TIFF_MM => 'tiff',
		IMAGETYPE_JPC => 'jpeg',
		IMAGETYPE_IFF => 'iff',
		IMAGETYPE_WBMP => 'bmp'
	);

	if ((int) $mime > 0)
	{
		$ext = isset($validImageTypes[$mime]) ? $validImageTypes[$mime] : '';
	}
	elseif (strpos($mime, '/'))
	{
		$ext = substr($mime, strpos($mime, '/') + 1);
	}
	else
	{
		$ext = $mime;
	}

	$ext = strtolower($ext);
	foreach ($validImageTypes as $valid_ext)
	{
		if ($valid_ext === $ext)
		{
			return 'image/' . $ext;
		}
	}

	return '';
}

/**
 * This function returns the mimeType of a file using the best means available
 *
 * @param string $filename
 * @return bool|mixed|string
 */
function get_finfo_mime($filename)
{
	$mimeType = false;

	// Check only existing readable files
	if (!file_exists($filename) || !is_readable($filename))
	{
		return $mimeType;
	}

	// Try finfo, this is the preferred way
	if (function_exists('finfo_open'))
	{
		$finfo = finfo_open(FILEINFO_MIME);
		$mimeType = finfo_file($finfo, $filename);
		finfo_close($finfo);
	}
	// No finfo? What? lets try the old mime_content_type
	elseif (function_exists('mime_content_type'))
	{
		$mimeType = mime_content_type($filename);
	}
	// Try using an exec call
	elseif (function_exists('exec'))
	{
		$mimeType = @exec("/usr/bin/file -i -b $filename");
	}

	// Still nothing? We should at least be able to get images correct
	if (empty($mimeType))
	{
		$imageData = elk_getimagesize($filename, 'none');
		if (!empty($imageData['mime']))
		{
			$mimeType = $imageData['mime'];
		}
	}

	// Account for long responses like text/plain; charset=us-ascii
	if (!empty($mimeType) && strpos($mimeType, ';'))
	{
		list($mimeType,) = explode(';', $mimeType);
	}

	return $mimeType;
}
