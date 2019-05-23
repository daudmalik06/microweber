<?php
/**
 * Backup V2
 * 
 * Class used to backup and restore the database or the userfiles directory.
 *
 * You can use it to create backup of the site. The backup will contain na sql export of the database
 * and also a zip file with userfiles directory.
 */
namespace Microweber\Utils;

use Microweber\Utils\Backup\BackupManager;

api_expose_admin('Microweber\Utils\BackupV2\get');
api_expose_admin('Microweber\Utils\BackupV2\restore');
api_expose_admin('Microweber\Utils\BackupV2\download');
api_expose_admin('Microweber\Utils\BackupV2\upload');
api_expose_admin('Microweber\Utils\BackupV2\delete');

if (defined('INI_SYSTEM_CHECK_DISABLED') == false) {
	define('INI_SYSTEM_CHECK_DISABLED', ini_get('disable_functions'));
}

class BackupV2
{

	public $app;
	public $manager;

	public function __construct($app = null)
	{
		if (! defined('USER_IP')) {
			if (isset($_SERVER['REMOTE_ADDR'])) {
				define('USER_IP', $_SERVER['REMOTE_ADDR']);
			} else {
				define('USER_IP', '127.0.0.1');
			}
		}

		if (is_object($app)) {
			$this->app = $app;
		} else {
			$this->app = mw();
		}

		if (! $this->manager) {
			$this->manager = new BackupManager();
		}
	}

	public function get()
	{
		if (! is_admin()) {
			error('must be admin');
		}

		$backupLocation = $this->manager->getBackupLocation();
		$backupFiles = glob("$backupLocation{*.sql,*.zip,*.json}", GLOB_BRACE);

		usort($backupFiles, function ($a, $b) {
			return filemtime($a) < filemtime($b);
		});

		$backups = array();
		if (! empty($backupFiles)) {
			foreach ($backupFiles as $file) {

				if (is_file($file)) {
					$mtime = filemtime($file);

					$backup = array();
					$backup['filename'] = basename($file);
					$backup['date'] = date('F d Y', $mtime);
					$backup['time'] = str_replace('_', ':', date('H:i:s', $mtime));
					$backup['size'] = filesize($file);

					$backups[] = $backup;
				}
			}
		}

		return $backups;
	}
	
	public function upload($query)
	{
		only_admin_access();
		
		if (! isset($query['src'])) {
			return array(
				'error' => 'You have not provided src to the file.'
			);
		}
		
		$checkFile = url2dir(trim($query['src']));
		
		$backupLocation = $this->manager->getBackupLocation();
		
		if (is_file($checkFile)) {
			$file = basename($checkFile);
			
			if (copy($checkFile, $backupLocation . $file)) {
				@unlink($checkFile);
				
				return array(
					'success' => "$file was moved!"
				);
			} else {
				return array(
					'error' => 'Error moving uploaded file!'
				);
			}
		} else {
			return array(
				'error' => 'Uploaded file is not found!'
			);
		}
	}

	public function download($query)
	{
		if (! is_admin()) {
			mw_error('must be admin');
		}

		if (isset($query['id'])) {
			$fileId = $query['id'];
		} elseif (isset($_GET['filename'])) {
			$fileId = $query['filename'];
		} elseif (isset($_GET['file'])) {
			$fileId = $query['file'];
		}
		$fileId = str_replace('..', '', $fileId);

		// Check if the file has needed args
		if (! $fileId) {
			return array(
				'error' => 'You have not provided filename to download.'
			);
		}

		$backupLocation = $this->manager->getBackupLocation();

		// Generate filename and set error variables
		$filename = $backupLocation . $fileId;
		$filename = str_replace('..', '', $filename);
		if (! is_file($filename)) {
			return array(
				'error' => 'You have not provided a existing filename to download.'
			);
		}

		// Check if the file exist.
		if (file_exists($filename)) {

			// Add headers
			header('Cache-Control: public');
			header('Content-Description: File Transfer');
			header('Content-Disposition: attachment; filename=' . basename($filename));
			header('Content-Length: ' . filesize($filename));

			// Read file
			$this->_readfileChunked($filename);
		} else {
			return array(
				'error' => 'File does not exist.'
			);
		}
	}
	
	public function restore($query) {
	
		only_admin_access();
		
		$fileId = null;
		if (isset($query['id'])) {
			$fileId = $query['id'];
		} elseif (isset($_GET['filename'])) {
			$fileId = $query['filename'];
		} elseif (isset($_GET['file'])) {
			$fileId = $query['file'];
		}
		
		if (!$fileId) {
			return array('error' => 'You have not provided a file to restore.');
		}
		
		$fileId = str_replace('..', '', $fileId);
		
		$backupLocation = $this->manager->getBackupLocation();
		$filePath = $backupLocation . $fileId;
		
		if (!is_file($filePath)) {
			return array('error' => 'You have not provided a existing backup to restore.');
		} else {
			
			$this->manager->setImportFile($filePath);
			$x = $this->manager->startImport();
			
			var_dump($x);
		}
		
		return $query;
		
	}

	public function delete($query)
	{
		if (! is_admin()) {
			error('must be admin');
		}

		// Get the provided arg
		$fileId = $query['id'];

		// Check if the file has needed args
		if (! $fileId) {
			return array(
				'error' => 'You have not provided filename to be deleted.'
			);
		}

		$backupLocation = $this->manager->getBackupLocation();
		$filename = $backupLocation . $fileId;

		$fileId = str_replace('..', '', $fileId);
		$filename = str_replace('..', '', $filename);

		if (is_file($filename)) {
			unlink($filename);
			return array(
				'success' => "$fileId was deleted!"
			);
		} else {
			$filename = $backupLocation . $fileId . '.sql';
			if (is_file($filename)) {
				unlink($filename);

				return array(
					'success' => "$fileId was deleted!"
				);
			}
		}
	}

	private function _readfileChunked($filename, $retbytes = true)
	{
		$filename = str_replace('..', '', $filename);

		$chunk_size = 1024 * 1024;
		$buffer = '';
		$cnt = 0;
		$handle = fopen($filename, 'rb');
		if ($handle === false) {
			return false;
		}

		while (! feof($handle)) {
			$buffer = fread($handle, $chunk_size);
			echo $buffer;
			ob_flush();
			flush();
			if ($retbytes) {
				$cnt += strlen($buffer);
			}
		}
		$status = fclose($handle);
		if ($retbytes && $status) {
			return $cnt; // return num. bytes delivered like readfile() does.
		}

		return $status;
	}
}