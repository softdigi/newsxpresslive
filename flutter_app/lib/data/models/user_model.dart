/// Represents an authenticated app user.
class UserModel {
  final String firebaseUid;
  final int?   backendId;
  final String? name;
  final String? email;
  final String? photoUrl;

  const UserModel({
    required this.firebaseUid,
    this.backendId,
    this.name,
    this.email,
    this.photoUrl,
  });

  UserModel copyWith({
    String? firebaseUid,
    int?    backendId,
    String? name,
    String? email,
    String? photoUrl,
  }) {
    return UserModel(
      firebaseUid: firebaseUid ?? this.firebaseUid,
      backendId:   backendId   ?? this.backendId,
      name:        name        ?? this.name,
      email:       email       ?? this.email,
      photoUrl:    photoUrl    ?? this.photoUrl,
    );
  }

  Map<String, dynamic> toJson() => {
    'firebase_uid': firebaseUid,
    'backend_id':   backendId,
    'name':         name,
    'email':        email,
    'photo_url':    photoUrl,
  };

  factory UserModel.fromJson(Map<String, dynamic> j) => UserModel(
    firebaseUid: j['firebase_uid'] as String,
    backendId:   j['backend_id']  as int?,
    name:        j['name']        as String?,
    email:       j['email']       as String?,
    photoUrl:    j['photo_url']   as String?,
  );
}
