<?php

final class PhabricatorAuthFactorTOTP extends PhabricatorAuthFactor {

  public function getFactorKey() {
    return 'totp';
  }

  public function getFactorName() {
    return pht('Mobile Phone App (TOTP)');
  }

  public function getFactorDescription() {
    return pht(
      'Attach a mobile authenticator application (like Authy '.
      'or Google Authenticator) to your account. When you need to '.
      'authenticate, you will enter a code shown on your phone.');
  }

  public function processAddFactorForm(
    AphrontFormView $form,
    AphrontRequest $request,
    PhabricatorUser $user) {


    $key = $request->getStr('totpkey');
    if (!strlen($key)) {
      // TODO: When the user submits a key, we should require that it be
      // one we generated for them, so there's no way an attacker can ever
      // force a key they control onto an account. However, it's clumsy to
      // do this right now. Once we have one-time tokens for SMS and email,
      // we should be able to put it on that infrastructure.
      $key = self::generateNewTOTPKey();
    }

    $code = $request->getStr('totpcode');

    $e_code = true;
    if ($request->getExists('totp')) {
      $okay = self::verifyTOTPCode(
        $user,
        new PhutilOpaqueEnvelope($key),
        $code);

      if ($okay) {
        $config = $this->newConfigForUser($user)
          ->setFactorName(pht('Mobile App (TOTP)'))
          ->setFactorSecret($key);

        return $config;
      } else {
        if (!strlen($code)) {
          $e_code = pht('Required');
        } else {
          $e_code = pht('Invalid');
        }
      }
    }

    $form->addHiddenInput('totp', true);
    $form->addHiddenInput('totpkey', $key);

    $form->appendRemarkupInstructions(
      pht(
        'First, download an authenticator application on your phone. Two '.
        'applications which work well are **Authy** and **Google '.
        'Authenticator**, but any other TOTP application should also work.'));

    $form->appendInstructions(
      pht(
        'Launch the application on your phone, and add a new entry for '.
        'this Phabricator install. When prompted, enter the key shown '.
        'below into the application.'));

    $form->appendChild(
      id(new AphrontFormStaticControl())
        ->setLabel(pht('Key'))
        ->setValue(phutil_tag('strong', array(), $key)));

    $form->appendInstructions(
      pht(
        '(If given an option, select that this key is "Time Based", not '.
        '"Counter Based".)'));

    $form->appendInstructions(
      pht(
        'After entering the key, the application should display a numeric '.
        'code. Enter that code below to confirm that you have configured '.
        'the authenticator correctly:'));

    $form->appendChild(
      id(new AphrontFormTextControl())
        ->setLabel(pht('TOTP Code'))
        ->setName('totpcode')
        ->setValue($code)
        ->setError($e_code));

  }

  public static function generateNewTOTPKey() {
    return strtoupper(Filesystem::readRandomCharacters(16));
  }

  public static function verifyTOTPCode(
    PhabricatorUser $user,
    PhutilOpaqueEnvelope $key,
    $code) {

    // TODO: This should use rate limiting to prevent multiple attempts in a
    // short period of time.

    $now = (int)(time() / 30);

    // Allow the user to enter a code a few minutes away on either side, in
    // case the server or client has some clock skew.
    for ($offset = -2; $offset <= 2; $offset++) {
      $real = self::getTOTPCode($key, $now + $offset);
      if ($real === $code) {
        return true;
      }
    }

    // TODO: After validating a code, this should mark it as used and prevent
    // it from being reused.

    return false;
  }


  public static function base32Decode($buf) {
    $buf = strtoupper($buf);

    $map = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $map = str_split($map);
    $map = array_flip($map);

    $out = '';
    $len = strlen($buf);
    $acc = 0;
    $bits = 0;
    for ($ii = 0; $ii < $len; $ii++) {
      $chr = $buf[$ii];
      $val = $map[$chr];

      $acc = $acc << 5;
      $acc = $acc + $val;

      $bits += 5;
      if ($bits >= 8) {
        $bits = $bits - 8;
        $out .= chr(($acc & (0xFF << $bits)) >> $bits);
      }
    }

    return $out;
  }

  public static function getTOTPCode(PhutilOpaqueEnvelope $key, $timestamp) {
    $binary_timestamp = pack('N*', 0).pack('N*', $timestamp);
    $binary_key = self::base32Decode($key->openEnvelope());

    $hash = hash_hmac('sha1', $binary_timestamp, $binary_key, true);

    // See RFC 4226.

    $offset = ord($hash[19]) & 0x0F;

    $code = ((ord($hash[$offset + 0]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
            ((ord($hash[$offset + 3])       )      );

    $code = ($code % 1000000);
    $code = str_pad($code, 6, '0', STR_PAD_LEFT);

    return $code;
  }

}