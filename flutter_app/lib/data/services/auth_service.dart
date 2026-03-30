import 'package:firebase_auth/firebase_auth.dart';
import 'package:google_sign_in/google_sign_in.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'dart:convert';
import '../models/user_model.dart';
import 'api_service.dart';
import '../../core/constants/api_endpoints.dart';

/// Handles Firebase Authentication (Google, Anonymous) and syncs the
/// authenticated user with the backend via [ApiEndpoints.userLogin].
class AuthService {
  AuthService._();

  static final AuthService instance = AuthService._();

  final FirebaseAuth  _auth    = FirebaseAuth.instance;
  final GoogleSignIn  _google  = GoogleSignIn();

  static const String _userKey = 'cached_user';

  // ── Current Firebase user ─────────────────────────────────────────────

  User? get firebaseUser => _auth.currentUser;

  Stream<User?> get authStateChanges => _auth.authStateChanges();

  bool get isSignedIn   => firebaseUser != null;
  bool get isAnonymous  => firebaseUser?.isAnonymous ?? true;

  // ── Google Sign-In ────────────────────────────────────────────────────

  /// Sign in with Google, then sync user to backend.
  /// Returns the app [UserModel] on success.
  Future<UserModel?> signInWithGoogle() async {
    try {
      final googleUser = await _google.signIn();
      if (googleUser == null) return null; // user cancelled

      final googleAuth = await googleUser.authentication;
      final credential = GoogleAuthProvider.credential(
        accessToken: googleAuth.accessToken,
        idToken:     googleAuth.idToken,
      );

      final result = await _auth.signInWithCredential(credential);
      return _syncWithBackend(result.user);
    } on FirebaseAuthException {
      rethrow;
    }
  }

  // ── Anonymous Sign-In ─────────────────────────────────────────────────

  /// Sign in anonymously. Used as a fallback when the user skips login.
  Future<UserModel?> signInAnonymously() async {
    try {
      final result = await _auth.signInAnonymously();
      // Anonymous users are not synced to the backend — no personal data.
      if (result.user == null) return null;
      return UserModel(firebaseUid: result.user!.uid);
    } on FirebaseAuthException {
      rethrow;
    }
  }

  // ── Sign Out ──────────────────────────────────────────────────────────

  Future<void> signOut() async {
    await Future.wait([
      _auth.signOut(),
      _google.signOut(),
    ]);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_userKey);
  }

  // ── Firebase ID token (for API auth headers) ──────────────────────────

  /// Returns a fresh Firebase ID token, or null if not signed in.
  Future<String?> getIdToken({bool forceRefresh = false}) async {
    try {
      return await firebaseUser?.getIdToken(forceRefresh);
    } catch (_) {
      return null;
    }
  }

  // ── Cache ─────────────────────────────────────────────────────────────

  Future<void> cacheUser(UserModel user) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_userKey, jsonEncode(user.toJson()));
  }

  Future<UserModel?> loadCachedUser() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw   = prefs.getString(_userKey);
      if (raw == null) return null;
      return UserModel.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  // ── Backend sync ──────────────────────────────────────────────────────

  Future<UserModel?> _syncWithBackend(User? user) async {
    if (user == null) return null;
    try {
      final api  = ApiService();
      final data = await api.postJson(ApiEndpoints.userLogin, body: {
        'firebase_uid': user.uid,
        'name':         user.displayName ?? '',
      });
      api.dispose();

      final backendId = data?['user_id'] as int?;
      final model = UserModel(
        firebaseUid: user.uid,
        backendId:   backendId,
        name:        user.displayName,
        email:       user.email,
        photoUrl:    user.photoURL,
      );
      await cacheUser(model);
      return model;
    } catch (_) {
      // Backend sync failed — still return a partial user so app is usable.
      return UserModel(
        firebaseUid: user.uid,
        name:        user.displayName,
        email:       user.email,
        photoUrl:    user.photoURL,
      );
    }
  }
}
