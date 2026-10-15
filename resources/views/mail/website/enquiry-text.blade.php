{{-- The plain-text part: printed as is, since nothing here is read as HTML. --}}
Website enquiry

Topic: {!! $enquiry['topic'] !!}
Name: {!! $enquiry['name'] !!}
Email: {!! $enquiry['email'] !!}
@if ($enquiry['phone'])
Phone: {!! $enquiry['phone'] !!}
@endif
@if ($enquiry['company'])
Company: {!! $enquiry['company'] !!}
@endif
Page language: {{ $enquiry['locale'] === 'bn' ? 'Bengali' : 'English' }}

{!! $enquiry['message'] !!}

Reply to this email to answer the visitor.
