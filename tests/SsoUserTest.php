<?php

use Laravel\Socialite\Contracts\User;
use Misakstvanu\ZssoClient\SkautisInfo;
use Misakstvanu\ZssoClient\SkautisStredisko;
use Misakstvanu\ZssoClient\SkautisUnit;
use Misakstvanu\ZssoClient\SsoUser;

it('maps a userinfo response without skautis', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload(), token: 'access-token', refreshToken: 'refresh-token', expiresIn: 900, scopes: ['profile', 'email']);

    expect($user->sub)->toBe('0192a3f4-1111-2222-3333-444455556666')
        ->and($user->email)->toBe('jana@example.cz')
        ->and($user->emailVerified)->toBeTrue()
        ->and($user->name)->toBe('Jana Nováková')
        ->and($user->nickname)->toBe('Žofka')
        ->and($user->skautisName)->toBe('Jana Nováková Skautová')
        ->and($user->skautisNickname)->toBe('Žofinka')
        ->and($user->skautisSex)->toBe('zena')
        ->and($user->skautisBirthday)->toBe('1997-03-01')
        ->and($user->birthday)->toBe('1998-04-02')
        ->and($user->street)->toBe('Dlouhá 1')
        ->and($user->city)->toBe('Brno')
        ->and($user->zip)->toBe('60200')
        ->and($user->avatarUrl)->toBeNull()
        ->and($user->skautis)->toBeNull()
        ->and($user->skautisUnit)->toBeInstanceOf(SkautisUnit::class)
        ->and($user->skautisStredisko)->toBeInstanceOf(SkautisStredisko::class)
        ->and($user->raw)->toBe(userinfoPayload())
        ->and($user->token)->toBe('access-token')
        ->and($user->refreshToken)->toBe('refresh-token')
        ->and($user->expiresIn)->toBe(900)
        ->and($user->scopes)->toBe(['profile', 'email']);
});

it('maps the skautis block', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload(['skautis' => skautisPayload() + ['login_id' => 'soap-token']]));

    expect($user->skautis)->toBeInstanceOf(SkautisInfo::class)
        ->and($user->skautis->userId)->toBe(12345)
        ->and($user->skautis->roleId)->toBe(678)
        ->and($user->skautis->unitId)->toBe(910)
        ->and($user->skautis->logoutAt?->format(DATE_ATOM))->toBe('2026-09-08T14:35:00+02:00')
        ->and($user->skautis->roles)->toBe(skautisPayload()['roles'])
        ->and($user->skautis->loginId)->toBe('soap-token');
});

it('leaves the login id null without the skautis:session scope', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload(['skautis' => skautisPayload()]));

    expect($user->skautis->loginId)->toBeNull()
        ->and($user->skautis->userId)->toBe(12345);
});

it('reads a skautis block that carries nothing but the user id', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload(['skautis' => ['user_id' => 91234]]));

    expect($user->skautis->userId)->toBe(91234)
        ->and($user->skautis->roleId)->toBeNull()
        ->and($user->skautis->unitId)->toBeNull()
        ->and($user->skautis->logoutAt)->toBeNull()
        ->and($user->skautis->roles)->toBe([]);
});

it('maps the unit and the středisko', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload());

    expect($user->skautisUnit?->id)->toBe(910)
        ->and($user->skautisUnit?->name)->toBe('12. oddíl Střelka')
        ->and($user->skautisUnit?->registrationNumber)->toBe('614.02.12')
        ->and($user->skautisUnit?->type)->toBe('oddil')
        ->and($user->skautisStredisko?->id)->toBe(900)
        ->and($user->skautisStredisko?->name)->toBe('středisko Lípa Praha 4')
        ->and($user->skautisStredisko?->registrationNumber)->toBe('614.02');
});

it('reads a unit whose lookup failed on the server, with only its id', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload([
        'unit' => ['id' => 910, 'name' => null, 'registration_number' => null, 'type' => null],
        'stredisko' => null,
    ]));

    expect($user->skautisUnit?->id)->toBe(910)
        ->and($user->skautisUnit?->name)->toBeNull()
        ->and($user->skautisUnit?->registrationNumber)->toBeNull()
        ->and($user->skautisUnit?->type)->toBeNull()
        ->and($user->skautisStredisko)->toBeNull();
});

it('omits the keys the granted scopes did not cover', function () {
    $user = SsoUser::fromUserinfo(['sub' => 'abc', 'updated_at' => '2026-09-08T12:00:00+02:00']);

    expect($user->email)->toBeNull()
        ->and($user->emailVerified)->toBeFalse()
        ->and($user->name)->toBeNull()
        ->and($user->skautisSex)->toBeNull()
        ->and($user->skautisBirthday)->toBeNull()
        ->and($user->avatarUrl)->toBeNull()
        ->and($user->skautis)->toBeNull()
        ->and($user->skautisUnit)->toBeNull()
        ->and($user->skautisStredisko)->toBeNull()
        ->and($user->token)->toBeNull()
        ->and($user->scopes)->toBe([]);
});

it('answers the socialite user contract', function () {
    $user = SsoUser::fromUserinfo(userinfoPayload(['avatar_url' => 'https://zsso.test/storage/avatars/jana.png']));

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->getId())->toBe('0192a3f4-1111-2222-3333-444455556666')
        ->and($user->getName())->toBe('Jana Nováková')
        ->and($user->getNickname())->toBe('Žofka')
        ->and($user->getEmail())->toBe('jana@example.cz')
        ->and($user->getAvatar())->toBe('https://zsso.test/storage/avatars/jana.png');
});

it('refuses a userinfo response without a sub', function () {
    SsoUser::fromUserinfo(['email' => 'jana@example.cz']);
})->throws(RuntimeException::class, 'carries no "sub"');
