// flutter_app/lib/data/services/biometric_service.dart
//
// Biometric / device-credential authentication service.
// Wraps `local_auth` with availability checks and persistent
// user-preference storage via SharedPreferences.
//
// Usage:
//   final bio = BiometricService.instance;
//   if (await bio.isAvailable()) {
//     final ok = await bio.authenticate();
//     if (ok) { /* proceed */ }
//   }

import 'package:flutter/services.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

class BiometricService {
  BiometricService._();
  static final BiometricService instance = BiometricService._();

  final LocalAuthentication _auth = LocalAuthentication();

  static const String _prefKey = 'biometric_login_enabled';

  // ── Capability checks ─────────────────────────────────────────────────

  /// Returns true if the device supports biometrics AND has enrolled
  /// fingerprints / face-ID.
  Future<bool> isAvailable() async {
    try {
      final canCheck = await _auth.canCheckBiometrics;
      final supported = await _auth.isDeviceSupported();
      if (!canCheck || !supported) return false;
      final enrolled = await _auth.getAvailableBiometrics();
      return enrolled.isNotEmpty;
    } on PlatformException {
      return false;
    }
  }

  /// Returns the list of enrolled biometric types (fingerprint, face, etc.).
  Future<List<BiometricType>> availableTypes() async {
    try {
      return await _auth.getAvailableBiometrics();
    } on PlatformException {
      return [];
    }
  }

  // ── Authentication ────────────────────────────────────────────────────

  /// Prompt the user for biometric (or device-credential) authentication.
  ///
  /// Returns true on success, false on failure / cancellation.
  Future<bool> authenticate({
    String localizedReason = 'Please authenticate to sign in',
  }) async {
    try {
      return await _auth.authenticate(
        localizedReason: localizedReason,
        options: const AuthenticationOptions(
          biometricOnly: false,   // allow PIN/pattern as fallback
          stickyAuth: true,       // re-prompt if app goes background
          sensitiveTransaction: true,
        ),
      );
    } on PlatformException catch (e) {
      // NotAvailable, NotEnrolled, LockedOut, etc.
      return false;
    }
  }

  // ── User preference ───────────────────────────────────────────────────

  /// Whether the user has opted-in to biometric login.
  Future<bool> isEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_prefKey) ?? false;
  }

  /// Persist the user's biometric-login preference.
  Future<void> setEnabled(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_prefKey, value);
  }

  // ── Convenience ───────────────────────────────────────────────────────

  /// Returns a human-readable label for the primary biometric type.
  Future<String> biometricLabel() async {
    final types = await availableTypes();
    if (types.contains(BiometricType.face)) return 'Face ID';
    if (types.contains(BiometricType.fingerprint)) return 'Fingerprint';
    if (types.contains(BiometricType.iris)) return 'Iris';
    return 'Biometric';
  }

  /// Cancel any in-progress biometric prompt (Android only).
  Future<void> cancelAuthentication() async {
    try {
      await _auth.stopAuthentication();
    } on PlatformException {
      // ignore
    }
  }
}
