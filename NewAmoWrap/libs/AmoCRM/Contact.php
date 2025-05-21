<?php

namespace AmoCRM_Wrap;

class Contact extends BaseEntity
{
    /**
     * @return array
     */
    protected function getExtraRaw()
    {
        return array();
    }

    /**
     * Добавляет текстовое примечание к контакту
     *
     * @param string $text
     * @return $this
     * @throws AmoWrapException
     */
    public function addNote($text) {
        if (empty($text)) {
            return $this;
        }

        if ($this->getId() === null) {
            $this->save();
        }

        $url = "/api/v4/contacts/notes";

        $postData = [
            [
                'entity_id' => (int)$this->getId(),
                'note_type' => 'common',
                'params' => [
                    'text' => $text
                ]
            ]
        ];

        $result = Base::cUrl($url, $postData, null, false, 'POST');

        return $this;
    }
}