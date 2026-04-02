import 'package:flutter/material.dart';
import '../../../../core/constants/app_strings.dart';

/// Comment submission form: name, optional e-mail, comment text, submit
/// button with loading state, and inline status / error message.
class CommentInputWidget extends StatelessWidget {
  const CommentInputWidget({
    super.key,
    required this.nameCtrl,
    required this.emailCtrl,
    required this.contentCtrl,
    required this.onSubmit,
    required this.submitting,
    required this.commMsg,
  });

  final TextEditingController nameCtrl;
  final TextEditingController emailCtrl;
  final TextEditingController contentCtrl;
  final VoidCallback          onSubmit;
  final bool                  submitting;
  final String                commMsg;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(AppStrings.leaveComment,
            style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 12),

        TextField(
          controller:      nameCtrl,
          decoration: const InputDecoration(
              labelText: AppStrings.commentNameHint),
          textInputAction: TextInputAction.next,
        ),
        const SizedBox(height: 10),

        TextField(
          controller:      emailCtrl,
          decoration: const InputDecoration(
              labelText: AppStrings.commentEmailHint),
          keyboardType:    TextInputType.emailAddress,
          textInputAction: TextInputAction.next,
        ),
        const SizedBox(height: 10),

        TextField(
          controller: contentCtrl,
          decoration: const InputDecoration(
              labelText: AppStrings.commentContentHint),
          maxLines:   4,
          maxLength:  1000,
        ),

        if (commMsg.isNotEmpty)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              commMsg,
              style: TextStyle(
                color:    commMsg.contains('submitted')
                    ? Colors.green
                    : Colors.red,
                fontSize: 13,
              ),
            ),
          ),

        const SizedBox(height: 12),
        SizedBox(
          width: double.infinity,
          child: ElevatedButton(
            onPressed: submitting ? null : onSubmit,
            child: submitting
                ? const SizedBox(
                    height: 18,
                    width:  18,
                    child: CircularProgressIndicator(
                        color: Colors.white, strokeWidth: 2))
                : const Text(AppStrings.postComment),
          ),
        ),
      ],
    );
  }
}
