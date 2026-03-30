/// Represents a news category.
class Category {
  final int    id;
  final String name;
  final String slug;

  const Category({
    required this.id,
    required this.name,
    required this.slug,
  });

  factory Category.fromJson(Map<String, dynamic> json) => Category(
    id:   int.tryParse(json['id'].toString()) ?? 0,
    name: json['name'] as String? ?? '',
    slug: json['slug'] as String? ?? '',
  );

  Map<String, dynamic> toJson() => {'id': id, 'name': name, 'slug': slug};

  @override
  bool operator ==(Object other) => other is Category && other.id == id;

  @override
  int get hashCode => id.hashCode;
}
