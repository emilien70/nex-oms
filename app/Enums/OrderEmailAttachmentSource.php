<?php

namespace App\Enums;

enum OrderEmailAttachmentSource: string
{
    case Document = 'document';
    case TemplateFile = 'template_file';
}
