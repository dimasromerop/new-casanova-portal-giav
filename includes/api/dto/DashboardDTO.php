<?php

class Casanova_Dashboard_DTO {

    public function __construct(
        public array $trips,
        public ?array $next_action,
        public array $payments,
        public array $messages,
        public array $mulligans
    ) {}

    public function to_array(): array {
        return [
            'trips'       => $this->trips,
            'next_action' => $this->next_action,
            'payments'    => $this->payments,
            'messages'    => $this->messages,
            'mulligans'   => $this->mulligans,
        ];
    }

    public static function from_array(array $data): self {
        return new self(
            $data['trips'],
            $data['next_action'],
            $data['payments'],
            $data['messages'],
            $data['mulligans']
        );
    }
}
