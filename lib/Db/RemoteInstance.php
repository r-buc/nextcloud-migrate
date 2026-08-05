<?php

declare(strict_types=1);

namespace OCA\NextcloudMigrate\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method string|null getLabel()
 * @method void setLabel(?string $label)
 * @method string getUrl()
 * @method void setUrl(string $url)
 * @method string getTargetUserId()
 * @method void setTargetUserId(string $targetUserId)
 * @method string getAppPasswordEncrypted()
 * @method void setAppPasswordEncrypted(string $appPasswordEncrypted)
 * @method bool getAllowSelfSigned()
 * @method void setAllowSelfSigned(bool $allowSelfSigned)
 * @method int|null getLastTestedAt()
 * @method void setLastTestedAt(?int $lastTestedAt)
 * @method string|null getLastTestError()
 * @method void setLastTestError(?string $lastTestError)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 */
class RemoteInstance extends Entity implements \JsonSerializable {
	protected $uuid;
	protected $label;
	protected $url;
	protected $targetUserId;
	protected $appPasswordEncrypted;
	protected $allowSelfSigned;
	protected $lastTestedAt;
	protected $lastTestError;
	protected $createdBy;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('allowSelfSigned', 'boolean');
		$this->addType('lastTestedAt', 'integer');
		$this->addType('createdAt', 'integer');
	}

	/**
	 * Redacted array for API responses; never includes the encrypted secret.
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'uuid' => $this->getUuid(),
			'label' => $this->getLabel(),
			'url' => $this->getUrl(),
			'targetUserId' => $this->getTargetUserId(),
			'allowSelfSigned' => $this->getAllowSelfSigned(),
			'lastTestedAt' => $this->getLastTestedAt(),
			'lastTestError' => $this->getLastTestError(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
