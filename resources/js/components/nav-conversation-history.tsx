import { Link, usePage } from '@inertiajs/react';
import { MessageSquareText } from 'lucide-react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';

type ConversationSession = {
    id: string;
    title: string;
    preview: string;
    last_activity_at?: string | null;
};

type SharedPageProps = {
    chat?: {
        sessions?: ConversationSession[];
    };
};

export function NavConversationHistory() {
    const page = usePage<SharedPageProps>();
    const sessions = page.props.chat?.sessions ?? [];
    const currentUrl = String(page.url ?? '');

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>AI Conversation History</SidebarGroupLabel>
            <SidebarMenu>
                {sessions.length === 0 && (
                    <SidebarMenuItem>
                        <SidebarMenuButton disabled tooltip={{ children: 'No conversations yet' }}>
                            <MessageSquareText />
                            <span>No conversations yet</span>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                )}

                {sessions.map((session) => {
                    const href = `/chat?conversation=${encodeURIComponent(session.id)}`;
                    return (
                        <SidebarMenuItem key={session.id}>
                            <SidebarMenuButton
                                asChild
                                isActive={currentUrl === href}
                                tooltip={{ children: session.title }}
                            >
                                <Link href={href} prefetch>
                                    <MessageSquareText />
                                    <span className="min-w-0 leading-tight">
                                        <span className="block truncate text-xs font-medium">
                                            {session.title}
                                        </span>
                                        <span className="block truncate text-[11px] text-muted-foreground">
                                            {session.preview}
                                        </span>
                                    </span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
