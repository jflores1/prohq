<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Services;

use Espo\ORM\Collection;
use Laminas\Mail\Message;

use Espo\Services\EmailAccount as EmailAccountService;
use Espo\Services\InboundEmail as InboundEmailService;

use Espo\Core\Utils\Json;

use Espo\Modules\Crm\Entities\CaseObj;
use Espo\ORM\Entity;
use Espo\Entities\User;
use Espo\Entities\Email as EmailEntity;
use Espo\Repositories\UserData as UserDataRepository;
use Espo\Tools\Email\Service;

use Espo\Entities\InboundEmail;
use Espo\Entities\EmailAccount;
use Espo\Entities\Attachment;
use Espo\Entities\UserData;

use Espo\Core\Acl\Table;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\ErrorSilent;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Di;
use Espo\Core\Mail\Exceptions\SendingError;
use Espo\Core\Mail\Sender;
use Espo\Core\Mail\SmtpParams;
use Espo\Core\Record\CreateParams;

use Exception;
use Throwable;
use stdClass;

/**
 * @extends Record<\Espo\Entities\Email>
 */
class Email extends Record implements

    Di\EmailSenderAware,
    Di\CryptAware,
    Di\FileStorageManagerAware
{
    use Di\EmailSenderSetter;
    use Di\CryptSetter;
    use Di\FileStorageManagerSetter;

    protected $getEntityBeforeUpdate = true;

    /**
     * @var string[]
     */
    protected $allowedForUpdateFieldList = [
        'parent',
        'teams',
        'assignedUser',
    ];

    protected $mandatorySelectAttributeList = [
        'name',
        'createdById',
        'dateSent',
        'fromString',
        'fromEmailAddressId',
        'fromEmailAddressName',
        'parentId',
        'parentType',
        'isHtml',
        'isReplied',
        'status',
        'accountId',
        'folderId',
        'messageId',
        'sentById',
        'replyToString',
        'hasAttachment',
        'groupFolderId',
    ];

    /**
     * @todo Move to Tools\Email.
     */
    public function getUserSmtpParams(string $userId): ?SmtpParams
    {
        /** @var ?User $user */
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);

        if (!$user) {
            return null;
        }

        $fromAddress = $user->getEmailAddress();

        if ($fromAddress) {
            $fromAddress = strtolower($fromAddress);
        }

        $smtpParams = null;

        if ($fromAddress) {
            $emailAccountService = $this->getEmailAccountService();

            $emailAccount = $emailAccountService->findAccountForUserForSending($user, $fromAddress);

            if ($emailAccount && $emailAccount->isAvailableForSending()) {
                $smtpParams = $emailAccountService->getSmtpParamsFromAccount($emailAccount);
            }
        }

        if (!$smtpParams) {
            return null;
        }

        $smtpParams['fromName'] = $user->getName();

        if ($fromAddress) {
            $this->applySmtpHandler($user->getId(), $fromAddress, $smtpParams);

            $smtpParams['fromAddress'] = $fromAddress;
        }

        return SmtpParams::fromArray($smtpParams);
    }

    /**
     * @todo Move to Tools\Email\SendService.
     *
     * @throws BadRequest
     * @throws SendingError
     * @throws Error
     */
    public function sendEntity(EmailEntity $entity, ?User $user = null): void
    {
        if (!$this->fieldValidationManager->check($entity, 'to', 'required')) {
            $entity->set('status', EmailEntity::STATUS_DRAFT);

            $this->entityManager->saveEntity($entity, ['silent' => true]);

            throw new BadRequest("Empty To address.");
        }

        $emailSender = $this->emailSender->create();

        $userAddressList = [];

        if ($user) {
            /** @var Collection<\Espo\Entities\EmailAddress> $emailAddressCollection */
            $emailAddressCollection = $this->entityManager
                ->getRDBRepository(User::ENTITY_TYPE)
                ->getRelation($user, 'emailAddresses')
                ->find();

            foreach ($emailAddressCollection as $ea) {
                $userAddressList[] = $ea->getLower();
            }
        }

        $originalFromAddress = $entity->getFromAddress();

        if (!$originalFromAddress) {
            throw new Error("Email sending: Can't send with empty 'from' address.");
        }

        $fromAddress = strtolower($originalFromAddress);

        $inboundEmail = null;
        $emailAccount = null;

        $smtpParams = null;

        if ($user && in_array($fromAddress, $userAddressList)) {
            $emailAccountService = $this->getEmailAccountService();

            $emailAccount = $emailAccountService->findAccountForUserForSending($user, $originalFromAddress);

            if ($emailAccount && $emailAccount->isAvailableForSending()) {
                $smtpParams = $emailAccountService->getSmtpParamsFromAccount($emailAccount);
            }

            if ($smtpParams) {
                $smtpParams['fromName'] = $user->getName();
            }
        }

        if ($user && $smtpParams) {
            $this->applySmtpHandler($user->getId(), $fromAddress, $smtpParams);

            $emailSender->withSmtpParams($smtpParams);
        }

        if (!$smtpParams) {
            $inboundEmailService = $this->getInboundEmailService();

            $inboundEmail = $user ?
                $inboundEmailService->findSharedAccountForUser($user, $originalFromAddress) :
                $inboundEmailService->findAccountForSending($originalFromAddress);

            if ($inboundEmail) {
                $smtpParams = $inboundEmailService->getSmtpParamsFromAccount($inboundEmail);
            }

            if ($smtpParams) {
                $emailSender->withSmtpParams($smtpParams);
            }
        }

        if (
            !$smtpParams &&
            $fromAddress === strtolower($this->config->get('outboundEmailFromAddress'))
        ) {
            if (!$this->config->get('outboundEmailIsShared')) {
                throw new Error("Email sending: Can not use system SMTP. System SMTP is not shared.");
            }

            $emailSender->withParams([
                'fromName' => $this->config->get('outboundEmailFromName'),
            ]);
        }

        if (!$smtpParams && !$this->config->get('outboundEmailIsShared')) {
            throw new Error("Email sending: No SMTP params found for {$fromAddress}.");
        }

        if (
            !$smtpParams &&
            $user &&
            in_array($fromAddress, $userAddressList)
        ) {
            $emailSender->withParams(['fromName' => $user->getName()]);
        }

        $params = [];

        $parent = null;
        $parentId = $entity->getParentId();
        $parentType = $entity->getParentType();

        if ($parentType && $parentId) {
            $parent = $this->entityManager->getEntityById($parentType, $parentId);
        }

        // @todo Refactor? Move to a separate class? Make extensible?
        if (
            $parent instanceof CaseObj &&
            $parent->getInboundEmailId()
        ) {
            /** @var string $inboundEmailId */
            $inboundEmailId = $parent->getInboundEmailId();

            /** @var ?InboundEmail $inboundEmail */
            $inboundEmail = $this->entityManager->getEntityById(InboundEmail::ENTITY_TYPE, $inboundEmailId);

            if ($inboundEmail && $inboundEmail->getReplyToAddress()) {
                $params['replyToAddress'] = $inboundEmail->getReplyToAddress();
            }
        }

        $this->validateEmailAddresses($entity);

        $message = new Message();

        $repliedMessageId = $this->getRepliedEmailMessageId($entity);

        if ($repliedMessageId) {
            $message->getHeaders()->addHeaderLine('In-Reply-To', $repliedMessageId);
            $message->getHeaders()->addHeaderLine('References', $repliedMessageId);
        }

        try {
            $emailSender
                ->withParams($params)
                ->withMessage($message)
                ->send($entity);
        }
        catch (Exception $e) {
            $entity->set('status', EmailEntity::STATUS_DRAFT);

            $this->entityManager->saveEntity($entity, ['silent' => true]);

            $this->log->error("Email sending:" . $e->getMessage() . "; " . $e->getCode());

            $errorData = [
                'id' => $entity->getId(),
                'message' => $e->getMessage(),
            ];

            throw ErrorSilent::createWithBody('sendingFail', Json::encode($errorData));
        }

        if ($inboundEmail) {
            $entity->addLinkMultipleId('inboundEmails', $inboundEmail->getId());
        }
        else if ($emailAccount) {
            $entity->addLinkMultipleId('emailAccounts', $emailAccount->getId());
        }

        $this->entityManager->saveEntity($entity, ['isJustSent' => true]);

        if ($inboundEmail) {
            if ($inboundEmail->storeSentEmails()) {
                try {
                    $inboundEmailService = $this->getInboundEmailService();

                    $inboundEmailService->storeSentMessage($inboundEmail, $message);
                }
                catch (Exception $e) {
                    $this->log->error(
                        "Email sending: Could not store sent email (Group Email Account {$inboundEmail->getId()}): " .
                        $e->getMessage() . "."
                    );
                }
            }
        }
        else if ($emailAccount) {
            if ($emailAccount->storeSentEmails()) {
                try {
                    $emailAccountService = $this->getEmailAccountService();

                    $emailAccountService->storeSentMessage($emailAccount, $message);
                }
                catch (Exception $e) {
                    $this->log->error(
                        "Email sending: Could not store sent email (Email Account {$emailAccount->getId()}): " .
                        $e->getMessage() . "."
                    );
                }
            }
        }

        if ($parent) {
            $this->getStreamService()->noteEmailSent($parent, $entity);
        }
    }

    /**
     * @param array<string,mixed> $params
     */
    protected function applySmtpHandler(string $userId, string $emailAddress, array &$params): void
    {
        $userData = $this->getUserDataRepository()->getByUserId($userId);

        if (!$userData) {
            return;
        }

        $smtpHandlers = $userData->get('smtpHandlers') ?? (object) [];

        if (!is_object($smtpHandlers)) {
            return;
        }

        if (!isset($smtpHandlers->$emailAddress)) {
            return;
        }

        /** @var class-string<object> $handlerClassName */
        $handlerClassName = $smtpHandlers->$emailAddress;

        try {
            $handler = $this->injectableFactory->create($handlerClassName);
        }
        catch (Throwable $e) {
            $this->log->error(
                "Email sending: Could not create Smtp Handler for {$emailAddress}. Error: " .
                $e->getMessage() . "."
            );

            return;
        }

        if (method_exists($handler, 'applyParams')) {
            $handler->applyParams($userId, $emailAddress, $params);
        }
    }

    /**
     * @throws Error
     */
    public function validateEmailAddresses(EmailEntity $entity): void
    {
        $from = $entity->getFromAddress();

        if ($from) {
            if (!filter_var($from, \FILTER_VALIDATE_EMAIL)) {
                throw new Error('From email address is not valid.');
            }
        }

        foreach ($entity->getToAddressList() as $address) {
            if (!filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                throw new Error('To email address is not valid.');
            }
        }

        foreach ($entity->getCcAddressList() as $address) {
            if (!filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                throw new Error('CC email address is not valid.');
            }
        }

        foreach ($entity->getBccAddressList() as $address) {
            if (!filter_var($address, \FILTER_VALIDATE_EMAIL)) {
                throw new Error('BCC email address is not valid.');
            }
        }
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws \Espo\Core\Exceptions\Forbidden
     * @throws \Espo\Core\Exceptions\Conflict
     * @throws \Espo\Core\Exceptions\BadRequest
     * @throws SendingError
     */
    public function create(stdClass $data, CreateParams $params): Entity
    {
        /** @var EmailEntity $entity */
        $entity = parent::create($data, $params);

        if ($entity->getStatus() === EmailEntity::STATUS_SENDING) {
            $this->sendEntity($entity, $this->user);
        }

        return $entity;
    }

    protected function beforeCreateEntity(Entity $entity, $data)
    {
        /** @var EmailEntity $entity */

        if ($entity->getStatus() === EmailEntity::STATUS_SENDING) {
            $messageId = Sender::generateMessageId($entity);

            $entity->set('messageId', '<' . $messageId . '>');
        }
    }

    /**
     * @throws BadRequest
     * @throws Error
     * @throws SendingError
     */
    protected function afterUpdateEntity(Entity $entity, $data)
    {
        /** @var EmailEntity $entity */

        if ($entity->getStatus() === EmailEntity::STATUS_SENDING) {
            $this->sendEntity($entity, $this->user);
        }

        $this->loadAdditionalFields($entity);

        if (!isset($data->from) && !isset($data->to) && !isset($data->cc)) {
            $entity->clear('nameHash');
            $entity->clear('idHash');
            $entity->clear('typeHash');
        }
    }

    public function getEntity(string $id): ?Entity
    {
        $entity = parent::getEntity($id);

        if ($entity && !$entity->get('isRead')) {
            $this->markAsRead($entity->getId());
        }

        return $entity;
    }

    private function markAsRead(string $id, ?string $userId = null): void
    {
        $service = $this->injectableFactory->create(Service::class);

        $service->markAsRead($id, $userId);
    }

    static public function parseFromName(?string $string): string
    {
        $fromName = '';

        if ($string && stripos($string, '<') !== false) {
            /** @var string $replacedString */
            $replacedString = preg_replace('/(<.*>)/', '', $string);

            $fromName = trim($replacedString, '" ');
        }

        return $fromName;
    }

    static public function parseFromAddress(?string $string): string
    {
        $fromAddress = '';

        if ($string) {
            if (stripos($string, '<') !== false) {
                if (preg_match('/<(.*)>/', $string, $matches)) {
                    $fromAddress = trim($matches[1]);
                }
            } else {
                $fromAddress = $string;
            }
        }

        return $fromAddress;
    }

    /**
     * @throws BadRequest
     * @throws Forbidden
     * @throws NotFound
     */
    public function getCopiedAttachments(
        string $id,
        ?string $parentType = null,
        ?string $parentId = null,
        ?string $field = null
    ): stdClass {

        $ids = [];
        $names = (object) [];

        if (empty($id)) {
            throw new BadRequest();
        }

        /** @var EmailEntity|null $email */
        $email = $this->entityManager->getEntity(EmailEntity::ENTITY_TYPE, $id);

        if (!$email) {
            throw new NotFound();
        }

        if (!$this->acl->checkEntity($email, Table::ACTION_READ)) {
            throw new Forbidden();
        }

        $email->loadLinkMultipleField('attachments');

        $attachmentsIds = $email->get('attachmentsIds');

        foreach ($attachmentsIds as $attachmentId) {
            /** @var ?Attachment $source */
            $source = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

            if ($source) {
                /** @var Attachment $attachment */
                $attachment = $this->entityManager->getNewEntity(Attachment::ENTITY_TYPE);

                $attachment->set('role', Attachment::ROLE_ATTACHMENT);
                $attachment->set('type', $source->getType());
                $attachment->set('size', $source->getSize());
                $attachment->set('global', $source->get('global'));
                $attachment->set('name', $source->getName());
                $attachment->set('sourceId', $source->getSourceId());
                $attachment->set('storage', $source->getStorage());

                if ($field) {
                    $attachment->set('field', $field);
                }

                if ($parentType) {
                    $attachment->set('parentType', $parentType);
                }

                if ($parentType && $parentId) {
                    $attachment->set('parentId', $parentId);
                }

                if ($this->fileStorageManager->exists($source)) {
                    $this->entityManager->saveEntity($attachment);

                    $contents = $this->fileStorageManager->getContents($source);

                    $this->fileStorageManager->putContents($attachment, $contents);

                    $ids[] = $attachment->getId();

                    $names->{$attachment->getId()} = $attachment->getName();
                }
            }
        }

        return (object) [
            'ids' => $ids,
            'names' => $names,
        ];
    }

    /**
     * @throws Forbidden
     * @throws NotFound
     */
    private function obtainSendTestEmailPassword(?string $type, ?string $id): ?string
    {
        if ($type === 'emailAccount') {
            if (!$this->acl->checkScope(EmailAccount::ENTITY_TYPE)) {
                throw new Forbidden();
            }

            if (!$id) {
                return null;
            }

            /** @var ?EmailAccount $emailAccount */
            $emailAccount = $this->entityManager->getEntityById(EmailAccount::ENTITY_TYPE, $id);

            if (!$emailAccount) {
                throw new NotFound();
            }

            if (
                !$this->user->isAdmin() &&
                $emailAccount->get('assignedUserId') !== $this->user->getId()
            ) {
                throw new Forbidden();
            }

            return $this->crypt->decrypt($emailAccount->get('smtpPassword'));
        }

        if (!$this->user->isAdmin()) {
            throw new Forbidden();
        }

        if ($type === 'inboundEmail') {
            if (!$id) {
                return null;
            }

            $emailAccount = $this->entityManager->getEntity(InboundEmail::ENTITY_TYPE, $id);

            if (!$emailAccount) {
                throw new NotFound();
            }

            return $this->crypt->decrypt($emailAccount->get('smtpPassword'));
        }

        return $this->config->get('smtpPassword');
    }

    /**
     * @param array{
     *     type?: ?string,
     *     id?: ?string,
     *     username?: ?string,
     *     password?: ?string,
     *     auth?: bool,
     *     authMechanism?: ?string,
     *     userId?: ?string,
     *     fromAddress?: ?string,
     *     fromName?: ?string,
     *     server: string,
     *     port: int,
     *     security: string,
     *     emailAddress: string,
     * } $data
     * @throws Forbidden
     * @throws Error
     * @throws NotFound
     */
    public function sendTestEmail(array $data): void
    {
        $smtpParams = $data;

        if (empty($smtpParams['auth'])) {
            unset($smtpParams['username']);
            unset($smtpParams['password']);
            unset($smtpParams['authMechanism']);
        }

        if (($smtpParams['password'] ?? null) === null) {
            $smtpParams['password'] = $this->obtainSendTestEmailPassword($data['type'] ?? null, $data['id'] ?? null);
        }

        $userId = $data['userId'] ?? null;
        $fromAddress = $data['fromAddress'] ?? null;
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        if (
            $userId &&
            $userId !== $this->user->getId() &&
            !$this->user->isAdmin()
        ) {
            throw new Forbidden();
        }

        /** @var EmailEntity $email */
        $email = $this->entityManager->getNewEntity(EmailEntity::ENTITY_TYPE);

        $email->set([
            'subject' => 'EspoCRM: Test Email',
            'isHtml' => false,
            'to' => $data['emailAddress'],
        ]);

        if ($type === 'emailAccount' && $id) {
            /** @var ?EmailAccount $emailAccount */
            $emailAccount = $this->entityManager->getEntityById(EmailAccount::ENTITY_TYPE, $id);

            if ($emailAccount && $emailAccount->get('smtpHandler')) {
                $this->getEmailAccountService()->applySmtpHandler($emailAccount, $smtpParams);
            }
        }

        if ($type === 'inboundEmail' && $id) {
            /** @var ?InboundEmail $inboundEmail */
            $inboundEmail = $this->entityManager->getEntityById(InboundEmail::ENTITY_TYPE, $id);

            if ($inboundEmail && $inboundEmail->get('smtpHandler')) {
                $this->getInboundEmailService()->applySmtpHandler($inboundEmail, $smtpParams);
            }
        }

        if ($userId && $fromAddress) {
            $this->applySmtpHandler($userId, $fromAddress, $smtpParams);
        }

        $emailSender = $this->emailSender;

        try {
            $emailSender
                ->withSmtpParams($smtpParams)
                ->send($email);
        }
        catch (Exception $e) {
            $this->log->warning("Email sending:" . $e->getMessage() . "; " . $e->getCode());

            $errorData = ['message' => $e->getMessage()];

            throw ErrorSilent::createWithBody('sendingFail', Json::encode($errorData));
        }
    }

    protected function beforeUpdateEntity(Entity $entity, $data)
    {
        /** @var EmailEntity $entity */

        $skipFilter = false;

        if ($this->user->isAdmin()) {
            $skipFilter = true;
        }

        if ($entity->isManuallyArchived()) {
            $skipFilter = true;
        } else {
            if ($entity->isAttributeChanged('dateSent')) {
                $entity->set('dateSent', $entity->getFetched('dateSent'));
            }
        }

        if ($entity->getStatus() === EmailEntity::STATUS_DRAFT) {
            $skipFilter = true;
        }

        if (
            $entity->getStatus() === EmailEntity::STATUS_SENDING &&
            $entity->getFetched('status') === EmailEntity::STATUS_DRAFT
        ) {
            $skipFilter = true;
        }

        if (
            $entity->isAttributeChanged('status') &&
            $entity->getFetched('status') === EmailEntity::STATUS_ARCHIVED
        ) {
            $entity->set('status', EmailEntity::STATUS_ARCHIVED);
        }

        if (!$skipFilter) {
            $this->clearEntityForUpdate($entity);
        }

        if ($entity->getStatus() == EmailEntity::STATUS_SENDING) {
            $messageId = Sender::generateMessageId($entity);

            $entity->set('messageId', '<' . $messageId . '>');
        }
    }

    private function clearEntityForUpdate(EmailEntity $email): void
    {
        $fieldDefsList = $this->entityManager
            ->getDefs()
            ->getEntity(EmailEntity::ENTITY_TYPE)
            ->getFieldList();

        foreach ($fieldDefsList as $fieldDefs) {
            $field = $fieldDefs->getName();

            if ($fieldDefs->getParam('isCustom')) {
                continue;
            }

            if (in_array($field, $this->allowedForUpdateFieldList)) {
                continue;
            }

            $attributeList = $this->fieldUtil->getAttributeList(EmailEntity::ENTITY_TYPE, $field);

            foreach ($attributeList as $attribute) {
                $email->clear($attribute);
            }
        }
    }

    private function getRepliedEmailMessageId(EmailEntity $email): ?string
    {
        $repliedLink = $email->getReplied();

        if (!$repliedLink) {
            return null;
        }

        /** @var EmailEntity|null $replied */
        $replied = $this->entityManager
            ->getRDBRepository(EmailEntity::ENTITY_TYPE)
            ->select(['messageId'])
            ->where(['id' => $repliedLink->getId()])
            ->findOne();

        if (!$replied) {
            return null;
        }

        return $replied->getMessageId();
    }

    private function getEmailAccountService(): EmailAccountService
    {
        /** @var EmailAccountService */
        return $this->injectableFactory->create(EmailAccountService::class);
    }

    private function getInboundEmailService(): InboundEmailService
    {
        /** @var InboundEmailService */
        return $this->injectableFactory->create(InboundEmailService::class);
    }

    private function getUserDataRepository(): UserDataRepository
    {
        /** @var UserDataRepository */
        return $this->entityManager->getRepository(UserData::ENTITY_TYPE);
    }
}
