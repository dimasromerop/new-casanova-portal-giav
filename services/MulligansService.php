<?php
namespace NewCasanovaPortalGiav\Services;

class MulligansService
{
    public function redeem(int $count): array
    {
        return ['redeemed' => $count];
    }
}
