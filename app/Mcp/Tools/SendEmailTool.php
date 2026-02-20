<?php

namespace App\Mcp\Tools;

class SendEmailTool extends SendGridEmailTool
{
    protected string $description = 'Send emails via SendGrid API. Alias tool for send_email.';
}
