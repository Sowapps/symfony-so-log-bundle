<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

namespace Sowapps\SoLog\Controller\Admin;

use DateTimeImmutable;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Psr\Log\LoggerInterface;
use Sowapps\SoCore\Core\Controller\AbstractAdminController;
use Sowapps\SoLog\Parser\LogEntry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminLogController extends AbstractAdminController {
	
	protected array $levels;
	
	public function view(Request $request, LoggerInterface $logger): Response {
		$this->addRequestToBreadcrumb($request);
		
		$handler = $this->getBestFileHandler($logger);
		$this->levels = array_combine(Level::NAMES, Level::VALUES);
		$level = Level::Error->value;
		
		if( $request->get('submitRemoveByKey') ) {
			$this->removeEntryByKey($handler->getUrl(), $request->get('submitRemoveByKey'));
			$this->addFlash('log_success', $this->translator->trans('page.so_core_admin_log_view.remove.success', [], 'admin'));
			$this->redirectToRequest($request);
			
		} elseif( $request->get('submitRemoveNonError') ) {
			$this->removeEntriesBelowLevel($handler->getUrl(), $level);
			$this->addFlash('log_success', $this->translator->trans('page.so_core_admin_log_view.removeNonErrorReports.success', [], 'admin'));
			$this->redirectToRequest($request);
			
		} elseif( $request->get('submitRemoveAll') ) {
			$this->eraseLog($handler->getUrl());
			$this->addFlash('log_success', $this->translator->trans('page.so_core_admin_log_view.removeAll.success', [], 'admin'));
			$this->redirectToRequest($request);
		}
		
		$log = $this->parseFileLog($handler->getUrl(), $level);
		
		return $this->render('@SoLog/admin/page/log-view.html.twig', [
			'info'       => [
				'logFile'    => $handler->getUrl(),
				'logLevel'   => $handler->getLevel()->getName(),
				'errorLevel' => $level,
			],
			'levels'     => array_flip($this->levels),
			'logEntries' => $log->entries,
			'otherCount' => $log->others,
		]);
	}
	
	/**
	 * @param LoggerInterface $logger
	 * @return StreamHandler|null
	 */
	public function getBestFileHandler(LoggerInterface $logger): ?StreamHandler {
		foreach( $logger->getHandlers() as $handler ) {
			if( $handler instanceof StreamHandler ) {
				return $handler;
			}
		}
		
		return null;
	}
	
	/**
	 * @param string $url
	 * @param string $key
	 * @return bool
	 * @throws Exception
	 */
	public function removeEntryByKey(string $url, string $key): bool {
		return $this->removeEntriesUsingFilter($url, function (LogEntry $logEntry, DateTimeImmutable $date) use ($key) {
			return $logEntry->getUniqueKey() !== $key;
		});
	}
	
	/**
	 * @param string $url Local file path to process
	 * @param callable $filter Filter returns true to keep line
	 * @return bool
	 */
	public function removeEntriesUsingFilter(string $url, callable $filter): bool {
		if( !file_exists($url) ) {
			return false;
		}
		$tempStream = tmpfile();
		$fileStream = fopen($url, 'r+');
		$line = 0;
		try {
			while( ($row = fgets($fileStream)) !== false ) {
				$line++;
				$logEntry = $this->parseRowLog($row, $date);
				if( call_user_func($filter, $logEntry, $date) === true ) {
					fwrite($tempStream, $row);
				}
			}
			rewind($tempStream);// Reset temp file pointer
			rewind($fileStream);// Reset log file pointer
			ftruncate($fileStream, 0);// Erase log file
			stream_copy_to_stream($tempStream, $fileStream);// Copy temp to log file
		} finally {
			fclose($fileStream);
			fclose($tempStream);
		}
		
		return true;
	}
	
	/**
	 * @param string $row
	 * @param DateTimeImmutable|null $date
	 * @return LogEntry
	 * @throws Exception
	 */
	public function parseRowLog(string $row, ?DateTimeImmutable &$date): LogEntry {
		if( !preg_match('#^\[([^\]]+)\] ([^\.\s]+)\.([^\.\s]+): (.+) ([\[\{].*[\}\]]) ([\[\{].*[\}\]])$#', $row, $matches) ) {
			throw new Exception('Unable to parse row');
		}
		$date = new DateTimeImmutable($matches[1]);
		
		// We ignore context & data for now
		return new LogEntry(
			$matches[2],
			$this->levels[$matches[3]] ?? null,
			str_replace('Uncaught PHP Exception ', '', $matches[4]),
			null);
	}
	
	public function removeEntriesBelowLevel($url, $level): bool {
		return $this->removeEntriesUsingFilter($url, function (LogEntry $logEntry, DateTimeImmutable $date) use ($level) {
			return $logEntry->getLevel() >= $level;
		});
	}
	
	public function eraseLog(string $path): bool {
		if( !file_exists($path) ) {
			return false;
		}
		
		return unlink($path);
	}
	
	/**
	 * @param string $url
	 * @param callable|int $levelMinOrFilter
	 * @return object
	 * @throws Exception
	 */
	public function parseFileLog(string $url, callable|int $levelMinOrFilter = 0): object {
		if( !file_exists($url) ) {
			return (object) ['entries' => [], 'others' => 0];
		}
		$stream = fopen($url, 'r');
		$logEntries = [];
		$otherCount = 0;
		$line = 0;
		while( ($row = fgets($stream)) !== false ) {
			$line++;
			if( !$row ) {
				continue;
			}
			$logEntry = $this->parseRowLog($row, $date);
			if( is_callable($levelMinOrFilter) ) {
				$keep = call_user_func($levelMinOrFilter, $logEntry);
			} else {
				$keep = $logEntry->getLevel() >= $levelMinOrFilter;
			}
			if( $keep ) {
				if( array_key_exists($logEntry->getUniqueKey(), $logEntries) ) {
					// Merge similar entries
					$logEntry = $logEntries[$logEntry->getUniqueKey()];
				} else {
					$logEntries[$logEntry->getUniqueKey()] = $logEntry;
				}
				$logEntry->addOccurrence($date, $line);
				$logEntry->calculateStatus();
				//				dump($logEntry);
			} else {
				$otherCount++;
			}
		}
		
		return (object) ['entries' => $logEntries, 'others' => $otherCount];
	}
	
}
