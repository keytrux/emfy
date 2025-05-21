<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

use AmoCRM_Wrap\AmoCRM;
use AmoCRM_Wrap\Lead;
use AmoCRM_Wrap\Token;
use AmoCRM_Wrap\Contact;
use AmoCRM_Wrap\Base;

require_once 'NewAmoWrap/autoload.php';
require_once __DIR__ . '/config.php';

// Получаем raw данные из тела запроса
$rawData = file_get_contents('php://input');

// Парсим URL-encoded данные в массив
parse_str($rawData, $data);

file_put_contents('data.log', print_r($data, true), FILE_APPEND);

if (empty($data)) {
    file_put_contents('errors.log', 'Ошибка: данные пустые или неправильно закодированы' . PHP_EOL, FILE_APPEND);
}

try {
    // данные для авторизации
    $authData = [
        'domain' => AMOCRM_SUBDOMAIN,
        'client_id' => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'redirect_uri' => 'https://keytrux.ru/emfy/webhook.php'
    ];

    // Получаем токен
    $token = new Token($authData);
    $accessToken = $token->getToken();

    $amo = new AmoCRM($authData['domain'], $token);

    if (!empty($data['leads']['add'])) {
        foreach ($data['leads']['add'] as $leadData) {
            $leadId = $leadData['id'];
            $leadName = $leadData['name'];
            $responsibleUserId = $leadData['responsible_user_id'];
            $createdAt = date("Y-m-d H:i:s", $leadData['created_at']);

            // Получаем имя ответственного из статического свойства класса AmoCRM
            $responsibleUserName = isset(AmoCRM::getUsers()[$responsibleUserId])
                ? AmoCRM::getUsers()[$responsibleUserId]
                : "Неизвестный ($responsibleUserId)";

            $lead = new Lead($leadId);

            $noteText = "Создана сделка: $leadName\nОтветственный: $responsibleUserName\nВремя: $createdAt";

            $lead->addNote($noteText);
        }
    }
    elseif (!empty($data['leads']['update'])) {
        // Загружаем кастомные поля один раз для всех сделок
        $customFieldsResponse = Base::cUrl("/api/v4/leads/custom_fields");
        $customFieldsMap = [];

        if (!empty($customFieldsResponse->_embedded->custom_fields)) {
            foreach ($customFieldsResponse->_embedded->custom_fields as $field) {
                $customFieldsMap[$field->id] = $field->name;
            }
        }

        foreach ($data['leads']['update'] as $leadData) {
            $leadId = $leadData['id'];
            $leadName = $leadData['name'];
            $last_modified = date("Y-m-d H:i:s", $leadData['last_modified']);
            $modifiedUserId = $leadData['modified_user_id'];

            $lead = new Lead($leadId);

            // Формируем текст примечания
            $noteText = "Обновлена сделка: $leadName\n";

            // Получаем события изменений сделки
            $events = Base::cUrl("/api/v4/events?filter[entity]=lead&filter[entity_id]=$leadId&order[created_at]=asc");

            $changes = [];

            if (!empty($events->_embedded->events)) {
                foreach ($events->_embedded->events as $event) {
                    // Пропускаем события без даты
                    if (empty($event->created_at)) continue;

                    // Проверяем, не слишком ли старое событие
                    $eventTime = $event->created_at;
                    $webhookTime = $leadData['last_modified'];

                    // Разница во времени (в секундах)
                    $timeDiff = abs($eventTime - $webhookTime);

                    // Принимаем события, которые произошли не раньше чем за 2 сек до/после webhookTime
                    if ($timeDiff > 2) continue;

                    // Разрешенные типы событий
                    if (str_contains($event->type, 'changed'))
                    {
                        if ($event->type === 'sale_field_changed') {
                            $oldSale = $event->value_before[0]->sale_field_value->sale ?? null;
                            $newSale = $event->value_after[0]->sale_field_value->sale ?? null;

                            if ($oldSale !== null && $newSale !== null) {
                                $changes[] = [
                                    'field' => 'Бюджет',
                                    'old' => $oldSale,
                                    'new' => $newSale
                                ];
                            }

                        }
                        elseif ($event->type === 'name_field_changed') {
                            $oldName = $event->value_before[0]->name_field_value->name ?? null;
                            $newName = $event->value_after[0]->name_field_value->name ?? null;

                            if ($oldName !== null && $newName !== null) {
                                $changes[] = [
                                    'field' => 'Название',
                                    'old' => $oldName,
                                    'new' => $newName
                                ];
                            }

                        }
                        else {
                            $fieldId = null;

                            if (!empty($event->value_before[0]->custom_field_value->field_id)) {
                                $fieldId = $event->value_before[0]->custom_field_value->field_id;
                            } elseif (!empty($event->value_after[0]->custom_field_value->field_id)) {
                                $fieldId = $event->value_after[0]->custom_field_value->field_id;
                            }

                            // Получаем имя поля или ставим заглушку
                            $fieldName = "Неизвестное поле";
                            if ($fieldId && isset($customFieldsMap[$fieldId])) {
                                $fieldName = $customFieldsMap[$fieldId];
                            } elseif ($fieldId) {
                                $fieldName = "Кастомное поле '$fieldId'";
                            }

                            // Распаковываем значения
                            $oldValue = "";
                            $newValue = "";

                            if (!empty($event->value_before) && is_array($event->value_before)) {
                                if (!empty($event->value_before[0]->custom_field_value->text)) {
                                    $oldValue = $event->value_before[0]->custom_field_value->text;
                                } elseif (isset($event->value_before[0]->custom_field_value->text)) {
                                    $oldValue = "";
                                }
                            }

                            if (!empty($event->value_after) && is_array($event->value_after)) {
                                if (!empty($event->value_after[0]->custom_field_value->text)) {
                                    $newValue = $event->value_after[0]->custom_field_value->text;
                                } elseif (isset($event->value_after[0]->custom_field_value->text)) {
                                    $newValue = "";
                                }
                            }

                            // Добавляем в изменения, если значения изменились или поле было пустым/стало заполненным
                            if ($oldValue !== $newValue) {
                                $changes[] = [
                                    'field' => $fieldName,
                                    'old' => $oldValue === "" ? "(пусто)" : $oldValue,
                                    'new' => $newValue === "" ? "(пусто)" : $newValue
                                ];
                            }
                        }
                    }
                }
            }

            // Добавляем информацию об изменениях в примечание
            if (!empty($changes)) {
                $noteText .= "\nИзмененные поля:\n";
                foreach ($changes as $change) {
                    $noteText .= "- {$change['field']}: было " . json_encode($change['old'], JSON_UNESCAPED_UNICODE) . ", стало " . json_encode($change['new'], JSON_UNESCAPED_UNICODE) . "\n";
                }
            } else {
                $noteText .= "\nНет данных о конкретных изменениях.\n";
            }

            $noteText .= "Время обновления: $last_modified";

            $lead->addNote($noteText);
        }
    }
    elseif (!empty($data['contacts']['add'])) {
        foreach ($data['contacts']['add'] as $contactData) {
            $contactId = $contactData['id'];
            $contactName = $contactData['name'];
            $responsibleUserId = $contactData['responsible_user_id'];
            $createdAt = date("Y-m-d H:i:s", $contactData['created_at']);

            // Получаем имя ответственного из статического свойства класса AmoCRM
            $responsibleUserName = isset(AmoCRM::getUsers()[$responsibleUserId])
                ? AmoCRM::getUsers()[$responsibleUserId]
                : "Неизвестный ($responsibleUserId)";

            $contact = new Contact($contactId);
            $noteText = "Создан контакт: $contactName\nОтветственный: $responsibleUserName\nВремя: $createdAt";

            $contact->addNote($noteText);
        }
    }
    elseif (!empty($data['contacts']['update']))
    {
        // Получаем список кастомных полей контакта
        $customFieldsResponse = Base::cUrl("/api/v4/contacts/custom_fields");
        $customFieldsMap = [];

        if (!empty($customFieldsResponse->_embedded->custom_fields)) {
            foreach ($customFieldsResponse->_embedded->custom_fields as $field) {
                $customFieldsMap[$field->id] = $field->name;
            }
        }

        foreach ($data['contacts']['update'] as $contactData) {
            $contactId = $contactData['id'];
            $contactName = $contactData['name'];
            $responsibleUserId = $contactData['responsible_user_id'];
            $last_modified = date("Y-m-d H:i:s", $contactData['last_modified']);

            $contact = new Contact($contactId);

            // Формируем текст примечания
            $noteText = "Обновлен контакт: $contactName\n";

            // События контакта по id
            $events = Base::cUrl("/api/v4/events?filter[entity]=contact&filter[entity_id]=$contactId&order[created_at]=asc");

            $changes = [];

            if (!empty($events->_embedded->events)) {
                foreach ($events->_embedded->events as $event) {
                    // Пропускаем события без даты
                    if (empty($event->created_at)) continue;

                    if (abs($event->created_at - $contactData['last_modified']) > 2) continue;

                    // Обрабатываем только события изменения
                    if (str_contains($event->type, 'changed')) {
                        $fieldName = 'Поле';
                        $fieldId = null;

                        if ($event->type === 'name_field_changed') {
                            $oldName = $event->value_before[0]->name_field_value->name ?? null;
                            $newName = $event->value_after[0]->name_field_value->name ?? null;

                            if ($oldName !== null && $newName !== null) {
                                $changes[] = [
                                    'field' => 'Имя',
                                    'old' => $oldName,
                                    'new' => $newName
                                ];
                            }

                        }
                        else
                        {
                            $fieldId = null;

                            if (!empty($event->value_before[0]->custom_field_value->field_id)) {
                                $fieldId = $event->value_before[0]->custom_field_value->field_id;
                            } elseif (!empty($event->value_after[0]->custom_field_value->field_id)) {
                                $fieldId = $event->value_after[0]->custom_field_value->field_id;
                            }

                            // Получаем имя поля или ставим заглушку
                            $fieldName = "Неизвестное поле";
                            if ($fieldId && isset($customFieldsMap[$fieldId])) {
                                $fieldName = $customFieldsMap[$fieldId];
                            } elseif ($fieldId) {
                                $fieldName = "Кастомное поле '$fieldId'";
                            }

                            // Распаковываем значения
                            $oldValue = "";
                            $newValue = "";

                            // Обработка value_before
                            if (!empty($event->value_before) && is_array($event->value_before)) {
                                if (!empty($event->value_before[0]->custom_field_value->text)) {
                                    $oldValue = $event->value_before[0]->custom_field_value->text;
                                } elseif (isset($event->value_before[0]->custom_field_value->text)) {
                                    $oldValue = "";
                                }
                            }

                            // Обработка value_after
                            if (!empty($event->value_after) && is_array($event->value_after)) {
                                if (!empty($event->value_after[0]->custom_field_value->text)) {
                                    $newValue = $event->value_after[0]->custom_field_value->text;
                                } elseif (isset($event->value_after[0]->custom_field_value->text)) {
                                    $newValue = "";
                                }
                            }

                            // Добавляем в изменения, если значения изменились или поле было пустым/стало заполненным
                            if ($oldValue !== $newValue) {
                                $changes[] = [
                                    'field' => $fieldName,
                                    'old' => $oldValue === "" ? "(пусто)" : $oldValue,
                                    'new' => $newValue === "" ? "(пусто)" : $newValue
                                ];
                            }
                        }
                    }
                }
            }

            // Добавляем информацию об изменениях в примечание
            if (!empty($changes)) {
                $noteText .= "\nИзмененные поля:\n";
                foreach ($changes as $change) {
                    $noteText .= "- {$change['field']}: было " . json_encode($change['old'], JSON_UNESCAPED_UNICODE) . ", стало " . json_encode($change['new'], JSON_UNESCAPED_UNICODE) . "\n";
                }
            } else {
                $noteText .= "\nНет данных о конкретных изменениях.\n";
            }

            $noteText .= "Время обновления: $last_modified";

            $contact->addNote($noteText);
        }
    }

} catch (Exception $e) {
    file_put_contents('debug.log', "Ошибка: " . $e->getMessage() . "\n", FILE_APPEND);
}
?>