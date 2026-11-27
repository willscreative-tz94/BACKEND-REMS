<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Str;

/** Branded email copy of an in-app notification (NotificationService). */
class NotificationMail extends Mailable
{
    use Queueable;

    /**
     * Notification type prefix => [category label, emoji, call-to-action].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    private const CATEGORIES = [
        'sale' => ['Sales & offers', '🤝', 'Review the offer'],
        'payment' => ['Payments', '💳', 'View payment'],
        'viewing' => ['Viewings', '📅', 'See the viewing'],
        'contract' => ['Contracts', '✍️', 'Open the contract'],
        'customer' => ['Customers', '👤', 'View customer'],
        'enquiry' => ['Enquiries', '📨', 'Reply to enquiry'],
        'message' => ['Messages', '💬', 'Read the message'],
        'commission' => ['Commissions', '💰', 'View commission'],
        'property' => ['Listings', '🏠', 'View listing'],
        'verification' => ['Verification', '🛡️', 'Review verification'],
    ];

    public EmailBrand $brand;

    public function __construct(
        public User $user,
        public string $type,
        public string $title,
        public ?string $body = null,
        public ?string $link = null,
    ) {
        $this->brand = EmailBrand::current();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "[{$this->brand->name}] {$this->title}",
            replyTo: $this->brand->replyTo ? [new Address($this->brand->replyTo, $this->brand->name)] : [],
            tags: ['notification', Str::before($this->type, '.')],
        );
    }

    public function content(): Content
    {
        [$category, $emoji, $cta] = self::CATEGORIES[Str::before($this->type, '.')] ?? ['Update', '🔔', 'Open in '.$this->brand->name];

        return new Content(
            view: 'emails.notification',
            text: 'emails.notification-text',
            with: [
                'brand' => $this->brand,
                'category' => $category,
                'emoji' => $emoji,
                'cta' => $cta,
                'url' => $this->link ? rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($this->link, '/') : null,
                'firstName' => Str::before(trim($this->user->name), ' ') ?: $this->user->name,
                'sentAt' => now()->format('D j M Y · H:i'),
                'preheader' => Str::limit($this->body ?: $this->title, 110),
            ],
        );
    }
}
