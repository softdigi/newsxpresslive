/// Represents an authenticated app user.
class UserModel {
  final String  firebaseUid;
  final int?    backendId;
  final String? name;
  final String? email;
  final String? photoUrl;

  // Blue-tick / verification fields
  final String? accountType;         // user | reporter | agency
  final bool    isBlueTick;
  final String? blueTickType;        // free | paid | agency_assigned | null
  final String? verificationStatus;  // unverified | pending | approved | rejected
  final bool    profileComplete;

  const UserModel({
    required this.firebaseUid,
    this.backendId,
    this.name,
    this.email,
    this.photoUrl,
    this.accountType,
    this.isBlueTick      = false,
    this.blueTickType,
    this.verificationStatus,
    this.profileComplete = false,
  });

  UserModel copyWith({
    String?  firebaseUid,
    int?     backendId,
    String?  name,
    String?  email,
    String?  photoUrl,
    String?  accountType,
    bool?    isBlueTick,
    String?  blueTickType,
    String?  verificationStatus,
    bool?    profileComplete,
  }) {
    return UserModel(
      firebaseUid:        firebaseUid        ?? this.firebaseUid,
      backendId:          backendId          ?? this.backendId,
      name:               name               ?? this.name,
      email:              email              ?? this.email,
      photoUrl:           photoUrl           ?? this.photoUrl,
      accountType:        accountType        ?? this.accountType,
      isBlueTick:         isBlueTick         ?? this.isBlueTick,
      blueTickType:       blueTickType       ?? this.blueTickType,
      verificationStatus: verificationStatus ?? this.verificationStatus,
      profileComplete:    profileComplete    ?? this.profileComplete,
    );
  }

  Map<String, dynamic> toJson() => {
    'firebase_uid':        firebaseUid,
    'backend_id':          backendId,
    'name':                name,
    'email':               email,
    'photo_url':           photoUrl,
    'account_type':        accountType,
    'is_blue_tick':        isBlueTick,
    'blue_tick_type':      blueTickType,
    'verification_status': verificationStatus,
    'profile_complete':    profileComplete,
  };

  factory UserModel.fromJson(Map<String, dynamic> j) => UserModel(
    firebaseUid:        j['firebase_uid']        as String,
    backendId:          j['backend_id']           as int?,
    name:               j['name']                 as String?,
    email:              j['email']                as String?,
    photoUrl:           j['photo_url']            as String?,
    accountType:        j['account_type']         as String?,
    isBlueTick:         j['is_blue_tick']         == true,
    blueTickType:       j['blue_tick_type']       as String?,
    verificationStatus: j['verification_status']  as String?,
    profileComplete:    j['profile_complete']     == true,
  );
}
