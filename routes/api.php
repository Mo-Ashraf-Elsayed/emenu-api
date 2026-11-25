<?php

use App\Modules\Identity\IdentityController;
use App\Modules\Identity\VerifiedEmail;
use App\Modules\Memberships\InvitationController;
use App\Modules\Memberships\MembershipController;
use App\Modules\PublicMenus\LimitPublicMenu;
use App\Modules\PublicMenus\PublicMenuController;
use App\Modules\Restaurants\RestaurantController;
use App\Modules\Tenancy\ResolveTenant;
use App\Shared\Http\HealthController;
use App\Shared\Http\JsonObject;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'live']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::prefix('v1/public/menus/{slug}')->where(['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'])->group(function (): void {
    Route::get('/', [PublicMenuController::class, 'show'])
        ->middleware(LimitPublicMenu::class.':120')->name('public-menu.show');
    Route::get('media/{media}/{variant}', [PublicMenuController::class, 'media'])
        ->whereUuid('media')->whereIn('variant', ['thumbnail', 'display'])
        ->middleware(LimitPublicMenu::class.':300')->name('public-menu.media');
});

Route::middleware('throttle:emenu-api')
    ->prefix('v1')->group(function (): void {
        Route::prefix('auth')->name('identity.')->group(function (): void {
            Route::middleware([JsonObject::class, 'throttle:emenu-guest'])->group(function (): void {
                Route::post('register', [IdentityController::class, 'register'])->name('register');
                Route::post('login', [IdentityController::class, 'login'])->name('login');
                Route::post('password/forgot', [IdentityController::class, 'forgot'])->name('forgot');
                Route::post('password/reset', [IdentityController::class, 'reset'])->name('reset');
            });

            Route::middleware('auth:sanctum')->group(function (): void {
                Route::get('me', [IdentityController::class, 'me'])->name('me');
                Route::post('logout', [IdentityController::class, 'logout'])->name('logout');
                Route::post('email/verification-notification', [IdentityController::class, 'resend'])
                    ->middleware('throttle:emenu-sensitive')->name('resend');
                Route::middleware([JsonObject::class, 'throttle:emenu-sensitive'])->group(function (): void {
                    Route::post('email/verify', [IdentityController::class, 'verify'])->name('verify');
                    Route::post('password/change', [IdentityController::class, 'change'])->name('change');
                    Route::post('sessions/invalidate-others', [IdentityController::class, 'invalidate'])->name('invalidate');
                });
            });
        });

        Route::middleware(['auth:sanctum', VerifiedEmail::class])->group(function (): void {
            Route::post('invitations/accept', [InvitationController::class, 'accept'])
                ->middleware([JsonObject::class, 'throttle:emenu-sensitive'])->name('invitations.accept');

            Route::get('restaurants', [RestaurantController::class, 'index'])->name('restaurants.index');
            Route::post('restaurants', [RestaurantController::class, 'store'])->middleware(JsonObject::class)->name('restaurants.store');

            Route::prefix('restaurants/{restaurant}')->where(['restaurant' => '[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}'])->middleware(ResolveTenant::class)
                ->group(function (): void {
                    Route::get('/', [RestaurantController::class, 'show'])->name('restaurants.show');
                    Route::patch('/', [RestaurantController::class, 'update'])->middleware(JsonObject::class)->name('restaurants.update');
                    Route::delete('/', [RestaurantController::class, 'archive']);

                    require __DIR__.'/menu.php';

                    Route::get('memberships', [MembershipController::class, 'index'])->name('memberships.index');
                    Route::patch('memberships/{membership}', [MembershipController::class, 'update'])
                        ->whereUuid('membership')->middleware(JsonObject::class)->name('memberships.update');
                    Route::delete('memberships/{membership}', [MembershipController::class, 'revoke'])->whereUuid('membership');
                    Route::post('ownership-transfer', [MembershipController::class, 'transfer'])
                        ->middleware([JsonObject::class, 'throttle:emenu-sensitive'])->name('memberships.transfer');

                    Route::get('invitations', [InvitationController::class, 'index'])->name('invitations.index');
                    Route::post('invitations', [InvitationController::class, 'store'])
                        ->middleware([JsonObject::class, 'throttle:emenu-sensitive'])->name('invitations.store');
                    Route::post('invitations/{invitation}/resend', [InvitationController::class, 'resend'])
                        ->whereUuid('invitation')->middleware('throttle:emenu-sensitive')->name('invitations.resend');
                    Route::delete('invitations/{invitation}', [InvitationController::class, 'revoke'])->whereUuid('invitation');
                });

            Route::prefix('platform/restaurants')->name('platform.restaurants.')->middleware('can:platform-admin')->group(function (): void {
                Route::get('/', [RestaurantController::class, 'platformIndex'])->name('index');
                Route::get('{restaurant}', [RestaurantController::class, 'platformShow'])->whereUuid('restaurant')->name('show');
                Route::patch('{restaurant}/status', [RestaurantController::class, 'platformStatus'])
                    ->whereUuid('restaurant')->middleware(JsonObject::class)->name('status');
            });
        });
    });
