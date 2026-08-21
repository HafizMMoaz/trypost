<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class YouTubeController extends SocialController
{
    protected string $driver = 'google';

    protected SocialPlatform $platform = SocialPlatform::YouTube;

    protected array $scopes = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return $this->redirectToGoogle();
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        $reconnect = $this->reconnectAccount($workspace);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $channels = $this->fetchChannels($socialUser->token);

            if (empty($channels)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_youtube_channels'), $this->platform->value);
            }

            $channels = $this->filterConnectableIdentities($workspace, $channels, 'id', $reconnect);

            if (empty($channels)) {
                return $this->noConnectableIdentities($reconnect, 'channel_not_found');
            }

            // If only one channel, connect directly (most common case)
            if (count($channels) === 1) {
                $channel = $channels[0];
                $avatarPath = uploadFromUrl(data_get($channel, 'thumbnail'));

                SocialAccount::connectIdentity(
                    $workspace,
                    $this->platform,
                    (string) data_get($channel, 'id'),
                    [
                        'username' => ltrim(data_get($channel, 'custom_url', data_get($channel, 'id')), '@'),
                        'display_name' => data_get($channel, 'title'),
                        'avatar_url' => $avatarPath,
                        'access_token' => $socialUser->token,
                        'refresh_token' => $socialUser->refreshToken,
                        'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                        'scopes' => $this->scopes,
                        'status' => Status::Connected,
                        'error_message' => null,
                        'disconnected_at' => null,
                        'meta' => [
                            'channel_id' => data_get($channel, 'id'),
                            'google_user_id' => $socialUser->getId(),
                        ],
                    ],
                    $reconnect,
                );

                return $this->connectedCallback($reconnect);
            }

            // Multiple channels - store data and show selection screen
            session([
                'youtube_oauth' => [
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'expires_in' => $socialUser->expiresIn,
                    'user_id' => $socialUser->getId(),
                    'reconnect_id' => $reconnect?->id,
                ],
            ]);

            return redirect()->route('app.social.youtube.select-channel');
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('YouTube OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function selectChannel(Request $request): InertiaResponse
    {
        $oauthData = session('youtube_oauth');

        if (! $oauthData) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = $this->connectWorkspace($request);

        $channels = $this->fetchChannels(data_get($oauthData, 'access_token'));

        if (empty($channels)) {
            $this->forgetSocialConnectSession();
            session()->forget('youtube_oauth');

            return $this->popupCallback(false, __('accounts.popup_callback.no_youtube_channels'), $this->platform->value);
        }

        $reconnect = $this->reconnectAccount($workspace);
        $channels = $this->filterConnectableIdentities($workspace, $channels, 'id', $reconnect);

        if (empty($channels)) {
            session()->forget('youtube_oauth');

            return $this->noConnectableIdentities($reconnect, 'channel_not_found');
        }

        // Unlike the Facebook and Instagram pickers, a deferred re-GET of this
        // route hits the Google API again, and fetchChannels() turns any
        // failure into an empty list that clears the connect session.
        return Inertia::render('accounts/YouTubeChannelSelect', [
            'workspace' => $workspace,
            'channels' => $channels,
            'onboardingProgress' => false,
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $request->validate([
            'channel_id' => 'required|string',
        ]);

        $oauthData = session('youtube_oauth');

        if (! $oauthData) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = $this->connectWorkspace($request);

        try {
            $reconnect = $this->reconnectAccount($workspace, data_get($oauthData, 'reconnect_id'));
            $channels = $this->filterConnectableIdentities(
                $workspace,
                $this->fetchChannels(data_get($oauthData, 'access_token')),
                'id',
                $reconnect,
            );
            $selectedChannel = collect($channels)->firstWhere('id', $request->channel_id);

            if (! $selectedChannel) {
                return $this->popupCallback(false, __('accounts.popup_callback.channel_not_found'), $this->platform->value);
            }

            $avatarPath = uploadFromUrl(data_get($selectedChannel, 'thumbnail'));

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                (string) data_get($selectedChannel, 'id'),
                [
                    'username' => ltrim(data_get($selectedChannel, 'custom_url', data_get($selectedChannel, 'id')), '@'),
                    'display_name' => data_get($selectedChannel, 'title'),
                    'avatar_url' => $avatarPath,
                    'access_token' => data_get($oauthData, 'access_token'),
                    'refresh_token' => data_get($oauthData, 'refresh_token'),
                    'token_expires_at' => data_get($oauthData, 'expires_in') ? now()->addSeconds(data_get($oauthData, 'expires_in')) : null,
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'channel_id' => data_get($selectedChannel, 'id'),
                        'google_user_id' => data_get($oauthData, 'user_id'),
                    ],
                ],
                $reconnect,
            );

            session()->forget('youtube_oauth');

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('YouTube channel selection error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting_channel'), $this->platform->value);
        }
    }

    private function redirectToGoogle(): Response
    {
        return Inertia::location(
            Socialite::driver($this->driver)
                ->scopes($this->scopes)
                ->with([
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'include_granted_scopes' => 'true',
                ])
                ->redirect()
                ->getTargetUrl()
        );
    }

    private function fetchChannels(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(config('trypost.platforms.youtube.data_api').'/channels', [
                    'part' => 'snippet,contentDetails,statistics',
                    'mine' => 'true',
                ]);

            if ($response->failed()) {
                Log::error('YouTube channels fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();

            return collect(data_get($data, 'items', []))->map(fn ($channel) => [
                'id' => data_get($channel, 'id'),
                'title' => data_get($channel, 'snippet.title'),
                'description' => data_get($channel, 'snippet.description', ''),
                'thumbnail' => data_get($channel, 'snippet.thumbnails.default.url'),
                'custom_url' => data_get($channel, 'snippet.customUrl'),
                'subscriber_count' => data_get($channel, 'statistics.subscriberCount', 0),
            ])->toArray();
        } catch (\Exception $e) {
            Log::error('YouTube channels fetch error', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
