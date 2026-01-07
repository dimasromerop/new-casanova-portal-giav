<?php

class Casanova_Dashboard_Service {

    public static function build_for_user(int $user_id): Casanova_Dashboard_DTO {

        $casanova_idcliente = get_user_meta($user_id, 'casanova_idcliente', true);

        if (!$casanova_idcliente) {
            throw new Exception('Cliente no vinculado a GIAV');
        }

        // Cache key por cliente
        $cache_key = 'casanova_dashboard_' . $casanova_idcliente;
        $cached = get_transient($cache_key);

        if ($cached) {
            return Casanova_Dashboard_DTO::from_array($cached);
        }

        // 1. Viajes futuros (máx 3)
        $trips = Giav_Service::get_future_trips($casanova_idcliente, 3);

        // 2. Próxima acción real
        $next_action = self::detect_next_action($trips);

        // 3. Pagos
        $payments = Giav_Service::get_payments_summary($trips);

        // 4. Mensajes
        $messages = Giav_Service::get_messages_summary($casanova_idcliente);

        // 5. Mulligans
        $mulligans = Mulligans_Service::get_summary($user_id);

        $dto = new Casanova_Dashboard_DTO(
            $trips,
            $next_action,
            $payments,
            $messages,
            $mulligans
        );

        set_transient($cache_key, $dto->to_array(), 60); // TTL corto, GIAV manda

        return $dto;
    }

    private static function detect_next_action(array $trips): ?array {
        // Reglas que YA tenéis hoy
        // Nada nuevo aquí
        foreach ($trips as $trip) {
            if ($trip['payment_pending']) {
                return [
                    'type' => 'payment',
                    'label' => __('Pago pendiente', 'casanova'),
                    'description' => sprintf(
                        __('Quedan %s € por abonar', 'casanova'),
                        $trip['amount_pending']
                    ),
                    'due_date' => $trip['payment_due_date'],
                    'expediente_id' => $trip['id']
                ];
            }
        }

        return null;
    }
}

