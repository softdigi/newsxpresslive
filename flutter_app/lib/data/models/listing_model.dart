/// Data models for Classified & Marketplace feature.

class ListingCategory {
  final int id;
  final int? parentId;
  final String name;
  final String? nameHi;
  final String? icon;
  final String listingType; // sell | service | rent | wanted

  const ListingCategory({
    required this.id,
    this.parentId,
    required this.name,
    this.nameHi,
    this.icon,
    required this.listingType,
  });

  factory ListingCategory.fromJson(Map<String, dynamic> j) => ListingCategory(
        id: j['id'] as int,
        parentId: j['parent_id'] as int?,
        name: j['name'] as String,
        nameHi: j['name_hi'] as String?,
        icon: j['icon'] as String?,
        listingType: (j['listing_type'] as String?) ?? 'sell',
      );

  String get displayName => nameHi ?? name;
}

class ListingSummary {
  final int id;
  final String title;
  final String? thumb;
  final List<String> images;
  final double? price;
  final bool priceNegotiable;
  final String priceType;
  final String listingType;
  final String? city;
  final int? stateId;
  final int? districtId;
  final String category;
  final String? categoryHi;
  final String? categoryIcon;
  final bool isFeatured;
  final int viewsCount;
  final int savesCount;
  final String createdAt;

  const ListingSummary({
    required this.id,
    required this.title,
    this.thumb,
    required this.images,
    this.price,
    required this.priceNegotiable,
    required this.priceType,
    required this.listingType,
    this.city,
    this.stateId,
    this.districtId,
    required this.category,
    this.categoryHi,
    this.categoryIcon,
    required this.isFeatured,
    required this.viewsCount,
    required this.savesCount,
    required this.createdAt,
  });

  factory ListingSummary.fromJson(Map<String, dynamic> j) => ListingSummary(
        id: j['id'] as int,
        title: j['title'] as String,
        thumb: j['thumb'] as String?,
        images: List<String>.from(j['images'] as List? ?? []),
        price: (j['price'] as num?)?.toDouble(),
        priceNegotiable: (j['price_negotiable'] as bool?) ?? false,
        priceType: (j['price_type'] as String?) ?? 'fixed',
        listingType: (j['listing_type'] as String?) ?? 'sell',
        city: j['city'] as String?,
        stateId: j['state_id'] as int?,
        districtId: j['district_id'] as int?,
        category: (j['category'] as String?) ?? '',
        categoryHi: j['category_hi'] as String?,
        categoryIcon: j['category_icon'] as String?,
        isFeatured: (j['is_featured'] as bool?) ?? false,
        viewsCount: (j['views_count'] as int?) ?? 0,
        savesCount: (j['saves_count'] as int?) ?? 0,
        createdAt: (j['created_at'] as String?) ?? '',
      );

  String get priceDisplay {
    if (price == null) return 'Free';
    final base = '₹${price!.toStringAsFixed(0)}';
    switch (priceType) {
      case 'per_day':   return '$base/day';
      case 'per_month': return '$base/month';
      case 'per_hour':  return '$base/hr';
      case 'free':      return 'Free';
      default:          return base;
    }
  }
}

class ListingDetail extends ListingSummary {
  final String? userId;
  final int categoryId;
  final String description;
  final String? pincode;
  final double? latitude;
  final double? longitude;
  final String? contactName;
  final String? contactPhone;
  final bool showPhone;
  final String? contactWhatsapp;
  final String status;
  final String? expiresAt;
  final bool isSaved;

  const ListingDetail({
    required super.id,
    required super.title,
    super.thumb,
    required super.images,
    super.price,
    required super.priceNegotiable,
    required super.priceType,
    required super.listingType,
    super.city,
    super.stateId,
    super.districtId,
    required super.category,
    super.categoryHi,
    super.categoryIcon,
    required super.isFeatured,
    required super.viewsCount,
    required super.savesCount,
    required super.createdAt,
    this.userId,
    required this.categoryId,
    required this.description,
    this.pincode,
    this.latitude,
    this.longitude,
    this.contactName,
    this.contactPhone,
    this.showPhone = true,
    this.contactWhatsapp,
    required this.status,
    this.expiresAt,
    this.isSaved = false,
  });

  factory ListingDetail.fromJson(Map<String, dynamic> j) {
    final s = ListingSummary.fromJson(j);
    return ListingDetail(
      id: s.id,
      title: s.title,
      thumb: s.thumb,
      images: s.images,
      price: s.price,
      priceNegotiable: s.priceNegotiable,
      priceType: s.priceType,
      listingType: s.listingType,
      city: s.city,
      stateId: s.stateId,
      districtId: s.districtId,
      category: s.category,
      categoryHi: s.categoryHi,
      categoryIcon: s.categoryIcon,
      isFeatured: s.isFeatured,
      viewsCount: s.viewsCount,
      savesCount: s.savesCount,
      createdAt: s.createdAt,
      userId: j['user_id'] as String?,
      categoryId: (j['category_id'] as int?) ?? 0,
      description: (j['description'] as String?) ?? '',
      pincode: j['pincode'] as String?,
      latitude: (j['latitude'] as num?)?.toDouble(),
      longitude: (j['longitude'] as num?)?.toDouble(),
      contactName: j['contact_name'] as String?,
      contactPhone: j['contact_phone'] as String?,
      showPhone: (j['show_phone'] as bool?) ?? true,
      contactWhatsapp: j['contact_whatsapp'] as String?,
      status: (j['status'] as String?) ?? 'active',
      expiresAt: j['expires_at'] as String?,
      isSaved: (j['is_saved'] as bool?) ?? false,
    );
  }
}

class ListingFeedResponse {
  final List<ListingSummary> listings;
  final bool hasMore;
  final int? nextCursor;

  const ListingFeedResponse({
    required this.listings,
    required this.hasMore,
    this.nextCursor,
  });

  factory ListingFeedResponse.fromJson(Map<String, dynamic> j) =>
      ListingFeedResponse(
        listings: (j['listings'] as List<dynamic>)
            .map((e) => ListingSummary.fromJson(e as Map<String, dynamic>))
            .toList(),
        hasMore: (j['has_more'] as bool?) ?? false,
        nextCursor: j['next_cursor'] as int?,
      );
}

class MyListingItem extends ListingSummary {
  final String status;
  final String? expiresAt;

  const MyListingItem({
    required super.id,
    required super.title,
    super.thumb,
    required super.images,
    super.price,
    required super.priceNegotiable,
    required super.priceType,
    required super.listingType,
    super.city,
    super.stateId,
    super.districtId,
    required super.category,
    super.categoryHi,
    super.categoryIcon,
    required super.isFeatured,
    required super.viewsCount,
    required super.savesCount,
    required super.createdAt,
    required this.status,
    this.expiresAt,
  });

  factory MyListingItem.fromJson(Map<String, dynamic> j) {
    final s = ListingSummary.fromJson(j);
    return MyListingItem(
      id: s.id,
      title: s.title,
      thumb: s.thumb,
      images: s.images,
      price: s.price,
      priceNegotiable: s.priceNegotiable,
      priceType: s.priceType,
      listingType: s.listingType,
      city: s.city,
      stateId: s.stateId,
      districtId: s.districtId,
      category: s.category,
      categoryHi: s.categoryHi,
      categoryIcon: s.categoryIcon,
      isFeatured: s.isFeatured,
      viewsCount: s.viewsCount,
      savesCount: s.savesCount,
      createdAt: s.createdAt,
      status: (j['status'] as String?) ?? 'pending',
      expiresAt: j['expires_at'] as String?,
    );
  }
}
