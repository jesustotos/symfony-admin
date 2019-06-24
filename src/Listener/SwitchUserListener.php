<?php

/**
 * This file is part of the pdAdmin package.
 *
 * @package     pd-admin
 * @license     LICENSE
 * @author      Kerem APAYDIN <kerem@apaydin.me>
 * @link        https://github.com/appaydin/pd-admin
 */

namespace App\Listener;

use App\Entity\Account\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationCredentialsNotFoundException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Role\SwitchUserRole;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Event\SwitchUserEvent;
use Symfony\Component\Security\Http\SecurityEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * SwitchUserListener allows a user to impersonate another one temporarily
 * (like the Unix su command).
 *
 * @author Fabien Potencier <fabien@symfony.com>
 * @author Kerem APAYDIN <kerem@apaydin.me>
 */
class SwitchUserListener
{
    public const EXIT_VALUE = '_exit';

    private $tokenStorage;
    private $provider;
    private $userChecker;
    private $providerKey;
    private $accessDecisionManager;
    private $usernameParameter;
    private $role;
    private $logger;
    private $dispatcher;
    private $stateless;

    public function __construct(TokenStorageInterface $tokenStorage, UserProviderInterface $provider, UserCheckerInterface $userChecker, string $providerKey, AccessDecisionManagerInterface $accessDecisionManager, LoggerInterface $logger = null, string $usernameParameter = '_switch_user', string $role = 'ROLE_ALLOWED_TO_SWITCH', EventDispatcherInterface $dispatcher = null, bool $stateless = false)
    {
        if (empty($providerKey)) {
            throw new \InvalidArgumentException('$providerKey must not be empty.');
        }

        $this->tokenStorage = $tokenStorage;
        $this->provider = $provider;
        $this->userChecker = $userChecker;
        $this->providerKey = $providerKey;
        $this->accessDecisionManager = $accessDecisionManager;
        $this->usernameParameter = $usernameParameter;
        $this->role = $role;
        $this->logger = $logger;
        $this->dispatcher = $dispatcher;
        $this->stateless = $stateless;
    }

    /**
     * Handles the switch to another user.
     *
     * @throws \LogicException if switching to a user failed
     */
    public function __invoke(RequestEvent $event)
    {
        $request = $event->getRequest();
        $username = $request->get($this->usernameParameter) ?: $request->headers->get($this->usernameParameter);

        if (!$username) {
            return;
        }

        if (null === $this->tokenStorage->getToken()) {
            throw new AuthenticationCredentialsNotFoundException('Could not find original Token object.');
        }

        if (self::EXIT_VALUE === $username) {
            $this->tokenStorage->setToken($this->attemptExitUser($request));
        } else {
            try {
                var_dump($this->tokenStorage->setToken($this->attemptSwitchUser($request, $username)));
                exit('fak');
            } catch (AuthenticationException $e) {
                throw new \LogicException(sprintf('Switch User failed: "%s"', $e->getMessage()));
            }
        }

        if (!$this->stateless) {
            $request->query->remove($this->usernameParameter);
            $request->server->set('QUERY_STRING', http_build_query($request->query->all(), '', '&'));
            $response = new RedirectResponse($request->getUri(), 302);

            $event->setResponse($response);
        }
    }

    private function attemptSwitchUser(Request $request, $username)
    {
        $token = $this->tokenStorage->getToken();
        $originalToken = $this->getOriginalToken($token);

        if (null !== $originalToken) {
            if ($token->getUsername() === $username) {
                return $token;
            }

            throw new \LogicException('not_login_already_switched');
        }

        $user = $this->provider->loadUserByUsername($username);

        if (false === $this->accessDecisionManager->decide($token, [$this->role], $user)) {
            $exception = new AccessDeniedException('not_login_switch_authorized');
            $exception->setAttributes($this->role);

            throw $exception;
        }

        if (null !== $this->logger) {
            $this->logger->info('Attempting to switch to user.', ['username' => $username]);
        }

        $this->userChecker->checkPostAuth($user);

        // Block Own Account
        if ($token->getUsername() === $user->getUsername()) {
            throw new AccessDeniedException('not_login_own_account');
        }

        // User To Super_Admin Block Access
        if (!$token->getUser()->hasRole(User::ROLE_ALL_ACCESS)) {
            if ($user->hasRole(User::ROLE_ALL_ACCESS)) {
                throw new AccessDeniedException('not_login_super_admin');
            }
        }

        $roles = $user->getRoles();
        $roles[] = new SwitchUserRole('ROLE_PREVIOUS_ADMIN', $this->tokenStorage->getToken(), false);

        $token = new SwitchUserToken($user, $user->getPassword(), $this->providerKey, $roles, $token);

        if (null !== $this->dispatcher) {
            $switchEvent = new SwitchUserEvent($request, $token->getUser(), $token);
            $this->dispatcher->dispatch($switchEvent, SecurityEvents::SWITCH_USER);
            // use the token from the event in case any listeners have replaced it.
            $token = $switchEvent->getToken();
        }

        return $token;
    }

    /**
     * Attempts to exit from an already switched user.
     *
     * @return TokenInterface The original TokenInterface instance
     *
     * @throws AuthenticationCredentialsNotFoundException
     */
    private function attemptExitUser(Request $request)
    {
        if (null === ($currentToken = $this->tokenStorage->getToken()) || null === $original = $this->getOriginalToken($currentToken)) {
            throw new AuthenticationCredentialsNotFoundException('Could not find original Token object.');
        }

        if (null !== $this->dispatcher && $original->getUser() instanceof UserInterface) {
            $user = $this->provider->refreshUser($original->getUser());
            $switchEvent = new SwitchUserEvent($request, $user, $original);
            $this->dispatcher->dispatch(SecurityEvents::SWITCH_USER, $switchEvent);
            $original = $switchEvent->getToken();
        }

        return $original;
    }

    private function getOriginalToken(TokenInterface $token): ?TokenInterface
    {
        if ($token instanceof SwitchUserToken) {
            return $token->getOriginalToken();
        }

        foreach ($token->getRoles(false) as $role) {
            if ($role instanceof SwitchUserRole) {
                return $role->getSource();
            }
        }

        return null;
    }
}
