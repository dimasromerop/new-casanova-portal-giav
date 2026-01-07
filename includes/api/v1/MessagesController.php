<?php
namespace NewCasanovaPortalGiav\Estructure\Api\V1;

class MessagesController
{
    /**
     * List messages placeholder.
     *
     * @return array
     */
    public function list(): array
    {
        return [
            ['id' => 1, 'text' => 'Welcome message'],
        ];
    }
}
