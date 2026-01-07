<?php
if (!defined('ABSPATH')) exit;

/**
 * DTO de Trip/Expediente (contrato estable para API/React).
 */
class Casanova_Trip_DTO {

  /** @var array<string,mixed> */
  private array $data;

  /**
   * @param array<string,mixed> $data
   */
  public function __construct(array $data) {
    $this->data = $data;
  }

  /**
   * @return array<string,mixed>
   */
  public function to_array(): array {
    return $this->data;
  }

  /**
   * @param array<string,mixed> $data
   */
  public static function from_array(array $data): self {
    return new self($data);
  }
}
