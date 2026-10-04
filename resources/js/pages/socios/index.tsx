import { DataTable } from '@/components/data-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Opcion, type Paginado, type SharedData } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ColumnDef } from '@tanstack/react-table';
import { useState } from 'react';

interface SocioFila {
    id: number;
    nro_socio: number | null;
    nombre_completo: string;
    dni: string | null;
    email: string | null;
    telefono: string | null;
    categoria: string;
    sede: string;
    estado: string;
    estado_label: string;
}

interface Props {
    socios: Paginado<SocioFila>;
    filtros: { q: string; categoria: string; estado: string; sede: string };
    opciones: { categorias: Opcion[]; estados: Opcion[]; sedes: Opcion[] };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Socios', href: '/socios' }];

const columnas: ColumnDef<SocioFila>[] = [
    { accessorKey: 'nro_socio', header: 'Nº', cell: ({ row }) => row.original.nro_socio ?? '—' },
    {
        accessorKey: 'nombre_completo',
        header: 'Socio',
        cell: ({ row }) => (
            <Link href={route('socios.show', row.original.id)} className="font-medium hover:underline">
                {row.original.nombre_completo}
            </Link>
        ),
    },
    { accessorKey: 'dni', header: 'DNI', cell: ({ row }) => row.original.dni ?? '—' },
    { accessorKey: 'categoria', header: 'Categoría' },
    { accessorKey: 'sede', header: 'Sede' },
    { accessorKey: 'telefono', header: 'Teléfono', cell: ({ row }) => row.original.telefono ?? '—' },
    {
        accessorKey: 'estado_label',
        header: 'Estado',
        cell: ({ row }) => <Badge variant={row.original.estado === 'baja' ? 'secondary' : 'default'}>{row.original.estado_label}</Badge>,
    },
];

export default function SociosIndex({ socios, filtros, opciones }: Props) {
    const { auth } = usePage<SharedData>().props;
    const [q, setQ] = useState(filtros.q);

    const aplicar = (cambios: Partial<Props['filtros']>) => {
        router.get(route('socios.index'), { ...filtros, ...cambios }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Socios" />
            <div className="flex flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold">Socios</h1>
                    {auth.user.rol === 'tesorero' && (
                        <Button asChild>
                            <Link href={route('socios.create')}>Nuevo socio</Link>
                        </Button>
                    )}
                </div>

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        aplicar({ q });
                    }}
                >
                    <Input className="w-64" placeholder="Buscar por nombre, DNI o email" value={q} onChange={(e) => setQ(e.target.value)} />
                    <NativeSelect className="w-44" value={filtros.estado} onChange={(e) => aplicar({ estado: e.target.value })} aria-label="Estado">
                        <option value="vigentes">Vigentes</option>
                        <option value="todos">Todos</option>
                        {opciones.estados.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect
                        className="w-44"
                        value={filtros.categoria}
                        onChange={(e) => aplicar({ categoria: e.target.value })}
                        aria-label="Categoría"
                    >
                        <option value="">Todas las categorías</option>
                        {opciones.categorias.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <NativeSelect className="w-44" value={filtros.sede} onChange={(e) => aplicar({ sede: e.target.value })} aria-label="Sede">
                        <option value="">Todas las sedes</option>
                        {opciones.sedes.map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label}
                            </option>
                        ))}
                    </NativeSelect>
                    <Button type="submit" variant="secondary">
                        Buscar
                    </Button>
                </form>

                <DataTable columns={columnas} data={socios.data} emptyMessage="No hay socios con esos filtros." />

                <div className="text-muted-foreground flex items-center justify-between text-sm">
                    <span>
                        {socios.total} socios · página {socios.current_page} de {socios.last_page}
                    </span>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!socios.prev_page_url}
                            onClick={() => socios.prev_page_url && router.get(socios.prev_page_url)}
                        >
                            Anterior
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            disabled={!socios.next_page_url}
                            onClick={() => socios.next_page_url && router.get(socios.next_page_url)}
                        >
                            Siguiente
                        </Button>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
