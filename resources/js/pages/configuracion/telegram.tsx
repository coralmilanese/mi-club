import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';

interface Chat {
    id: number;
    chat_id: string;
    username: string | null;
    nombre: string | null;
    autorizado: boolean;
    ingestas_count: number;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Bot de Telegram', href: '/configuracion/telegram' }];

export default function TelegramConfig({
    chats,
    usuarios_configurados,
    bot_configurado,
}: {
    chats: Chat[];
    usuarios_configurados: string[];
    bot_configurado: boolean;
}) {
    const { auth } = usePage<SharedData>().props;
    const esTesorero = auth.user.rol === 'tesorero';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bot de Telegram" />
            <div className="flex max-w-3xl flex-col gap-4 p-4">
                <h1 className="text-2xl font-semibold">Bot de Telegram</h1>
                {!bot_configurado && <p className="text-amber-600">Falta configurar TELEGRAM_BOT_TOKEN en el servidor.</p>}
                <p className="text-muted-foreground text-sm">
                    Usuarios autorizados por configuración (se habilitan solos la primera vez que le escriben al bot):{' '}
                    {usuarios_configurados.length ? usuarios_configurados.map((u) => `@${u}`).join(', ') : 'ninguno'}.
                </p>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Usuario</TableHead>
                                <TableHead>Nombre</TableHead>
                                <TableHead>Chat ID</TableHead>
                                <TableHead>Mensajes</TableHead>
                                <TableHead>Autorizado</TableHead>
                                <TableHead />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {chats.map((c) => (
                                <TableRow key={c.id}>
                                    <TableCell>{c.username ? `@${c.username}` : '—'}</TableCell>
                                    <TableCell>{c.nombre ?? '—'}</TableCell>
                                    <TableCell className="text-muted-foreground text-xs">{c.chat_id}</TableCell>
                                    <TableCell>{c.ingestas_count}</TableCell>
                                    <TableCell>
                                        <Badge variant={c.autorizado ? 'default' : 'secondary'}>
                                            {c.autorizado ? 'Autorizado' : 'No autorizado'}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        {esTesorero && (
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() =>
                                                    router.put(
                                                        route('telegram-chats.update', c.id),
                                                        { autorizado: !c.autorizado },
                                                        { preserveScroll: true },
                                                    )
                                                }
                                            >
                                                {c.autorizado ? 'Revocar' : 'Autorizar'}
                                            </Button>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                            {chats.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-muted-foreground h-24 text-center">
                                        Todavía nadie le escribió al bot.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
