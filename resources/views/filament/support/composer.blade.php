{{-- Wrapper that mounts the self-contained inline reply composer. --}}
@livewire('admin-ticket-reply-composer', ['ticketId' => $ticketId], key('ticket-composer-' . $ticketId))
