<?php
/**
 * @author Florent HAZARD <f.hazard@sowapps.com>
 */

namespace Sowapps\SoLogBundle\Parser;

use DateTime;
use DateTimeInterface;
use Monolog\Logger;

class LogEntry {
	
	const STATUS_RARE = 'rare';
	const STATUS_OCCASIONALLY = 'occasionally';
	const STATUS_URGENT = 'urgent';
	const STATUS_SOLVED = 'solved';
	
	protected string $domain;
	
	protected int $level;
	
	protected string $message;
	
	protected ?array $data = null;
	
	protected array $occurrences = [];
	
	// Number of days in average the error occurres twice
	protected ?float $averageDays = null;
	
	protected ?int $lastToNowDays = null;
	
	protected ?string $status = null;
	
	/**
	 * LogRow constructor
	 *
	 * @param string $domain
	 * @param int $level
	 * @param string $message
	 * @param array|null $data
	 */
	public function __construct(string $domain, int $level, string $message, ?array $data = null) {
		$this->domain = $domain;
		$this->level = $level;
		$this->message = $message;
		$this->data = $data;
	}
	
	/**
	 * @return array
	 */
	public function getLines(): array {
		return array_filter(array_map(function ($occurrence) {
			return $occurrence[1];
		}, $this->occurrences));
	}
	
	public function calculateStatus(): void {
		$minDate = $maxDate = null;
		foreach( $this->occurrences as $occurrence ) {
			if( !$minDate || $occurrence[0] < $minDate ) {
				$minDate = $occurrence[0];
			}
			if( !$maxDate || $occurrence[0] > $maxDate ) {
				$maxDate = $occurrence[0];
			}
		}
		$occurrenceCount = count($this->occurrences);
		$deltaDay = $minDate->diff($maxDate)->format('%a') + 1;
		$this->lastToNowDays = $maxDate->diff(new DateTime())->format('%a');
		$this->averageDays = $deltaDay / $occurrenceCount;
		
		if( $this->lastToNowDays > max(2, min(3 * $this->averageDays, 14)) ) {
			// 3 x average day between error is solved
			// Urgents are waiting for 2 days at least
			// Rares don't wait for more than 2 weeks
			$this->status = static::STATUS_SOLVED;
			
		} elseif( $this->averageDays > 5 ) {
			$this->status = static::STATUS_RARE;
			
		} elseif( $this->averageDays <= 1 && $occurrenceCount > 2 ) {
			$this->status = static::STATUS_URGENT;
			
		} else {
			$this->status = static::STATUS_OCCASIONALLY;
		}
	}
	
	public function addOccurrence(DateTimeInterface $date, $line): void {
		$this->occurrences[] = [$date, $line];
	}
	
	public function getUniqueKey(): string {
		return (string) crc32($this->domain . '-' . $this->level . '-' . $this->message . '-' . json_encode($this->data));
	}
	
	public function getAverageDays(): ?float {
		return $this->averageDays;
	}
	
	public function getLastToNowDays(): ?int {
		return $this->lastToNowDays;
	}
	
	public function getStatus(): ?string {
		return $this->status;
	}
	
	public function getOccurrences(): array {
		return $this->occurrences;
	}
	
	public function isError(): bool {
		return $this->level >= Logger::ERROR;
	}
	
	public function getDomain(): string {
		return $this->domain;
	}
	
	public function getLevel(): int {
		return $this->level;
	}
	
	public function getMessage(): string {
		return $this->message;
	}
	
	public function getData(): ?array {
		return $this->data;
	}
	
}
