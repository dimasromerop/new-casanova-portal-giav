<?php
namespace NewCasanovaPortalGiav\Services;

class PaymentsService
{
    public function createPayment(array $data): array
    {
        return ['status' => 'created', 'data' => $data];
    }
}
